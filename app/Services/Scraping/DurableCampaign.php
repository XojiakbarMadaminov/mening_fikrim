<?php

namespace App\Services\Scraping;

use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\ScrapeCampaign;
use App\Models\ScrapeCampaignCheckpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/** Permanent P6 registry. Historical pilot cache entries are never changed here. */
class DurableCampaign
{
    private const LOCK_NAMESPACE = 1369487366;

    private const LOCK_KEY = 1;

    private const EXCLUDED_SOURCE_IDS = ['1587862', '-1587862', '1587860', '8354875', '-8345596'];

    private ?string $ownerToken = null;

    private ?string $ownedId = null;

    private ?int $ownerGeneration = null;

    private ?int $backendPid = null;

    public function exists(): bool
    {
        return Schema::hasTable('scrape_campaigns') && ScrapeCampaign::exists();
    }

    public function id(): ?string
    {
        return $this->latest()?->campaign_id;
    }

    private function latest(): ?ScrapeCampaign
    {
        return Schema::hasTable('scrape_campaigns') ? ScrapeCampaign::orderByDesc('id')->first() : null;
    }

    public function state(?string $id = null): ?array
    {
        $campaign = $id === null ? $this->latest() : ScrapeCampaign::where('campaign_id', $id)->first();
        if (! $campaign) {
            return null;
        }
        $state = $campaign->state;
        $terminal = in_array($campaign->status, ['stopped', 'completed', 'failed'], true);

        return [...$state, 'id' => $campaign->campaign_id, 'durable' => true, 'status' => $campaign->status,
            'request_limit' => $campaign->max_http_requests, 'used' => $campaign->used_http_requests,
            'target' => $campaign->target_new_canonical, 'stage' => $campaign->stage_target,
            'source_limit' => $campaign->max_source_selections, 'selected_source_count' => $campaign->selected_source_count,
            'baseline_max_id' => $campaign->baseline_max_id, 'baseline_count' => $campaign->baseline_canonical_count,
            'new_canonical_documents' => $this->newCount(['id' => $campaign->campaign_id, 'baseline_max_id' => $campaign->baseline_max_id]),
            'persisted_new_canonical_count' => $campaign->current_new_canonical_count,
            'started_at' => $campaign->started_at->toAtomString(),
            'paused_at' => $campaign->paused_at?->toAtomString(), 'completed_at' => $campaign->completed_at?->toAtomString(),
            'last_request_started_at' => $campaign->last_request_started_at?->format('Y-m-d\TH:i:s.uP'),
            'stopped' => $terminal ? ['reason' => $campaign->stop_reason ?? $campaign->status, 'at' => ($campaign->stopped_at ?? $campaign->completed_at)?->toAtomString()] : null,
            'configuration_snapshot' => $campaign->configuration_snapshot,
            'owner_generation' => $campaign->owner_generation, 'owner_backend_pid' => $campaign->owner_backend_pid,
            'owned_by_this_process' => $campaign->owner_token !== null && $campaign->owner_token === $this->ownerToken];
    }

    /** A new campaign obtains the global controller lock before its baseline is established. */
    public function create(string $id, int $requests, int $sources, int $target = 1000, array $configuration = []): array
    {
        if (! preg_match('/^[a-z0-9-]{1,60}$/D', $id) || $requests < 1 || $requests > 15000 || $sources < 1 || $sources > 3500 || $target < 1 || $target > 1000) {
            throw new InvalidArgumentException('Durable pilot caps: unique ID, 1..15000 requests, 1..3500 source selections and 1..1000 new canonical documents.');
        }
        $stages = array_values(array_filter([30, 100, 300, 600, 1000], fn ($stage) => $stage < $target));
        $stages[] = $target;
        $configuration = array_replace(['workers' => 1, 'request_interval_ms' => 2000, 'per_context_request_limit' => 400,
            'max_partitions' => 255, 'max_discovered_ids' => 20000, 'max_partition_depth' => 10,
            'page_cap' => 8, 'max_campaign_seconds' => 43200], $configuration);
        if ($configuration['workers'] !== 1 || $configuration['request_interval_ms'] < 2000 || $configuration['per_context_request_limit'] < 1 || $configuration['per_context_request_limit'] > 400
            || $configuration['max_partitions'] < 1 || $configuration['max_partitions'] > 1024 || $configuration['max_discovered_ids'] < 1 || $configuration['max_discovered_ids'] > 100000
            || $configuration['max_partition_depth'] < 0 || $configuration['max_partition_depth'] > 16 || $configuration['page_cap'] < 1 || $configuration['page_cap'] > 8
            || $configuration['max_campaign_seconds'] < 1 || $configuration['max_campaign_seconds'] > 86400) {
            throw new InvalidArgumentException('Campaign safety configuration must remain finite and conservative.');
        }
        $this->obtainLock();
        try {
            DB::transaction(function () use ($id, $requests, $sources, $target, $stages, $configuration) {
                $previous = $this->latest();
                if ($previous && $previous->status !== 'completed') {
                    throw new RuntimeException('Existing durable campaign must remain authoritative; do not bypass an open or terminal safety stop.');
                }
                $legacyId = Cache::get('lex:pilot:active');
                $legacy = $legacyId === null ? null : Cache::get('lex:pilot:'.$legacyId);
                if ($legacyId !== null && (! $legacy || ! in_array($legacy['stopped']['reason'] ?? null, ['approved_target_reached', 'bounded_source_selection_budget_exhausted'], true))) {
                    throw new RuntimeException('Legacy pilot is open, missing or safety-stopped; refuse to bypass it.');
                }
                if (ScrapeCampaign::where('campaign_id', $id)->exists() || DB::table('jobs')->where('queue', 'scraping')->exists()) {
                    throw new RuntimeException('Unique campaign ID and empty scraping queue are required.');
                }
                $source = DocumentSource::where('slug', 'lex')->where('is_active', true)->firstOrFail();
                ScrapeCampaign::create(['campaign_id' => $id, 'source_id' => $source->id, 'target_new_canonical' => $target,
                    'baseline_max_id' => (int) Document::max('id'), 'baseline_canonical_count' => Document::where('source_id', $source->id)->count(),
                    'max_source_selections' => $sources, 'max_http_requests' => $requests, 'stage_target' => $stages[0],
                    'started_at' => now(), 'configuration_snapshot' => $configuration,
                    'state' => ['stages' => $stages, 'selected_ids' => [], 'contexts' => [], 'audit_count' => null,
                        'consecutive_errors' => 0, 'http_status_counts' => [], 'discovery_partition_count' => 0, 'discovered_source_count' => 0,
                        'legacy_predecessor_id' => $legacyId, 'legacy_predecessor_sha256' => $legacy === null ? null : hash('sha256', json_encode($legacy))]]);
            });

            return $this->claim($id);
        } catch (\Throwable $e) {
            $this->unlock();
            throw $e;
        }
    }

    /** Session-level lock survives transactions and cannot be stolen during long PDF/OCR work. */
    public function claim(string $id): array
    {
        if ($this->ownedId !== null) {
            if ($this->ownedId !== $id) {
                throw new RuntimeException('This controller already owns a different campaign.');
            }
            $this->assertOwner();

            return $this->state($id);
        }
        $this->obtainLock();
        try {
            DB::transaction(function () use ($id) {
                $campaign = ScrapeCampaign::where('campaign_id', $id)->lockForUpdate()->firstOrFail();
                if ($this->id() !== $id || ! in_array($campaign->status, ['running', 'pausing', 'paused'], true)) {
                    throw new PilotStoppedException('terminal_or_superseded_campaign');
                }
                $this->ownerToken = (string) Str::uuid();
                $this->ownedId = $id;
                $this->ownerGeneration = $campaign->owner_generation + 1;
                $campaign->update(['owner_token' => $this->ownerToken, 'owner_generation' => $this->ownerGeneration,
                    'owner_backend_pid' => $this->backendPid, 'owner_claimed_at' => now()]);
            });

            return $this->state($id);
        } catch (\Throwable $e) {
            $this->unlock();
            throw $e;
        }
    }

    private function obtainLock(): void
    {
        if ($this->backendPid !== null) {
            if ($this->backendPid !== $this->currentPid()) {
                throw new RuntimeException('Controller PostgreSQL connection changed; explicit recovery required.');
            }

            return;
        }
        $alreadyHeld = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND classid = ?::oid AND objid = ?::oid AND granted) AS held", [self::LOCK_NAMESPACE, self::LOCK_KEY]);
        if ($alreadyHeld->held) {
            throw new RuntimeException('Another Lex.uz controller instance already owns this PostgreSQL session.');
        }
        $locked = DB::selectOne('SELECT pg_try_advisory_lock(?, ?) AS acquired', [self::LOCK_NAMESPACE, self::LOCK_KEY]);
        if (! $locked->acquired) {
            throw new RuntimeException('Another Lex.uz controller owns the global PostgreSQL session lock.');
        }
        $this->backendPid = $this->currentPid();
    }

    private function currentPid(): int
    {
        return (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    }

    public function assertOwner(): void
    {
        if ($this->ownerToken === null || $this->backendPid === null || $this->backendPid !== $this->currentPid()) {
            throw new RuntimeException('Campaign controller ownership missing or PostgreSQL connection replaced.');
        }
        $held = DB::selectOne("SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid() AND classid = ?::oid AND objid = ?::oid AND granted) AS held", [self::LOCK_NAMESPACE, self::LOCK_KEY]);
        $owner = ScrapeCampaign::where('campaign_id', $this->ownedId)->where('owner_token', $this->ownerToken)
            ->where('owner_generation', $this->ownerGeneration)->where('owner_backend_pid', $this->backendPid)->exists();
        if (! $held->held || ! $owner || $this->id() !== $this->ownedId) {
            throw new RuntimeException('Stale campaign controller fence rejected.');
        }
    }

    public function release(): void
    {
        if ($this->ownerToken !== null && $this->backendPid === $this->currentPid()) {
            ScrapeCampaign::where('campaign_id', $this->ownedId)->where('owner_token', $this->ownerToken)->where('owner_generation', $this->ownerGeneration)
                ->update(['owner_token' => null, 'owner_backend_pid' => null, 'owner_claimed_at' => null]);
        }
        $this->unlock();
    }

    private function unlock(): void
    {
        if ($this->backendPid !== null && $this->backendPid === $this->currentPid()) {
            DB::select('SELECT pg_advisory_unlock(?, ?)', [self::LOCK_NAMESPACE, self::LOCK_KEY]);
        }
        $this->ownerToken = null;
        $this->ownedId = null;
        $this->ownerGeneration = null;
        $this->backendPid = null;
    }

    public function assertOpen(): ?array
    {
        $state = $this->state();
        if ($state === null) {
            return null;
        }
        if ($state['status'] !== 'running') {
            throw new PilotStoppedException($state['stopped']['reason'] ?? 'campaign_'.$state['status']);
        }
        $this->assertOwner();
        if (now()->diffInSeconds($state['started_at'], true) >= $state['configuration_snapshot']['max_campaign_seconds']) {
            $this->stop('campaign_duration_budget_exhausted');
            throw new PilotStoppedException('campaign_duration_budget_exhausted');
        }

        return $state;
    }

    private function locked(): ScrapeCampaign
    {
        $this->assertOwner();

        return ScrapeCampaign::where('campaign_id', $this->ownedId)->where('owner_token', $this->ownerToken)
            ->where('owner_generation', $this->ownerGeneration)->lockForUpdate()->firstOrFail();
    }

    /** DB clock and persisted timestamp preserve the pacing floor after cache loss/restart. */
    public function secondsUntilNextRequest(): float
    {
        $this->assertOwner();
        $row = DB::selectOne('SELECT EXTRACT(EPOCH FROM (last_request_started_at - clock_timestamp())) AS seconds FROM scrape_campaigns WHERE campaign_id = ?', [$this->ownedId]);
        if ($row->seconds === null) {
            return 0.0;
        }
        $interval = max(2000, $this->state()['configuration_snapshot']['request_interval_ms']) / 1000;

        return max(0, $interval + (float) $row->seconds);
    }

    /** Reservation commits before outbound HTTP. A crash may burn a token, never replenish it. */
    public function reserve(string $context, string $method = 'GET', string $url = ''): string
    {
        $this->assertOpen();
        $error = DB::transaction(function () use ($context, $method, $url) {
            $campaign = $this->locked();
            $state = $campaign->state;
            if ($campaign->used_http_requests >= $campaign->max_http_requests) {
                $this->terminal($campaign, 'campaign_request_budget_exhausted');

                return 'campaign_request_budget_exhausted';
            }
            if (! preg_match('/^[a-zA-Z0-9:_-]{1,120}$/D', $context)) {
                throw new InvalidArgumentException('Request context must be bounded and contain no source secrets.');
            }
            if (($state['contexts'][$context] ?? 0) >= $campaign->configuration_snapshot['per_context_request_limit']) {
                $this->terminal($campaign, 'per_run_request_budget_exhausted');

                return 'per_run_request_budget_exhausted';
            }
            if (! isset($state['contexts'][$context]) && count($state['contexts']) >= 1024) {
                $this->terminal($campaign, 'request_context_budget_exhausted');

                return 'request_context_budget_exhausted';
            }
            $state['contexts'][$context] = ($state['contexts'][$context] ?? 0) + 1;
            $state['last_reservation'] = ['context' => $context, 'method' => $method, 'source_path' => parse_url($url, PHP_URL_PATH)];
            $campaign->update(['used_http_requests' => $campaign->used_http_requests + 1, 'last_request_started_at' => DB::raw('clock_timestamp()'), 'state' => $state]);

            return null;
        });
        if ($error !== null) {
            throw new PilotStoppedException($error);
        }

        return $this->ownedId;
    }

    public function observe(int $status): void
    {
        DB::transaction(function () use ($status) {
            $campaign = $this->locked();
            $state = $campaign->state;
            $state['http_status_counts'][(string) $status] = ($state['http_status_counts'][(string) $status] ?? 0) + 1;
            $state['consecutive_errors'] = $status === 0 || $status >= 500 ? $state['consecutive_errors'] + 1 : 0;
            $campaign->update(['state' => $state]);
            if (in_array($status, [403, 429], true) || $state['consecutive_errors'] >= 2) {
                $this->terminal($campaign, 'source_http_'.$status.($state['consecutive_errors'] >= 2 ? '_repeated' : ''));
            }
        });
    }

    public function selectSources(array $ids): void
    {
        $this->assertOpen();
        foreach ($ids as $id) {
            if (! is_string($id) || ! preg_match('/^-?\d+$/D', $id) || in_array($id, self::EXCLUDED_SOURCE_IDS, true)) {
                throw new InvalidArgumentException('Select signed string IDs only; unresolved source identities remain excluded.');
            }
        }
        $error = DB::transaction(function () use ($ids) {
            $campaign = $this->locked();
            $state = $campaign->state;
            $selected = array_values(array_unique([...$state['selected_ids'], ...$ids]));
            if (count($selected) > $campaign->max_source_selections) {
                $this->terminal($campaign, 'bounded_source_selection_budget_exhausted');

                return 'bounded_source_selection_budget_exhausted';
            }
            $state['selected_ids'] = $selected;
            $campaign->update(['state' => $state, 'selected_source_count' => count($selected)]);

            return null;
        });
        if ($error !== null) {
            throw new PilotStoppedException($error);
        }
    }

    public function newCount(?array $state = null): int
    {
        $state ??= ($campaign = $this->latest()) ? ['id' => $campaign->campaign_id, 'baseline_max_id' => $campaign->baseline_max_id] : null;
        if ($state === null) {
            return 0;
        }

        return DB::table('scrape_run_items AS item')->join('scrape_runs AS run', 'run.id', '=', 'item.scrape_run_id')
            ->join('documents AS document', 'document.id', '=', 'item.document_id')
            ->where('run.checkpoint->campaign_id', $state['id'])->where('item.action', 'created')
            ->where('document.id', '>', $state['baseline_max_id'])->distinct()->count('document.id');
    }

    public function auditedStage(array $errors): void
    {
        $this->assertOpen();
        if ($errors || DB::table('jobs')->where('queue', 'scraping')->exists()) {
            $this->stop('stage_integrity_audit_failed');
            throw new PilotStoppedException('stage_integrity_audit_failed');
        }
        DB::transaction(function () {
            $campaign = $this->locked();
            $count = $this->newCount();
            $state = $campaign->state;
            $state['audit_count'] = $count;
            $state['last_audited_at'] = now()->toAtomString();
            $campaign->update(['state' => $state, 'current_new_canonical_count' => $count]);
        });
    }

    public function advance(int $stage): array
    {
        $this->assertOpen();
        DB::transaction(function () use ($stage) {
            $campaign = $this->locked();
            $state = $campaign->state;
            $index = array_search($campaign->stage_target, $state['stages'], true);
            $next = $index === false ? null : ($state['stages'][$index + 1] ?? null);
            if ($stage !== $next || ($state['audit_count'] ?? null) !== $campaign->stage_target || $this->newCount() !== $campaign->stage_target || DB::table('jobs')->where('queue', 'scraping')->exists()) {
                throw new RuntimeException('Stage requires exact canonical target, empty queue and current passing preservation/archive audit.');
            }
            $state['audit_count'] = null;
            $campaign->update(['stage_target' => $stage, 'state' => $state]);
        });

        return $this->state();
    }

    public function stop(string $reason): void
    {
        DB::transaction(fn () => $this->terminal($this->locked(), $reason));
    }

    private function terminal(ScrapeCampaign $campaign, string $reason): void
    {
        if (in_array($campaign->status, ['stopped', 'failed', 'completed'], true)) {
            return;
        }
        $campaign->update(['status' => 'stopped', 'stopped_at' => now(), 'stop_reason' => mb_substr($reason, 0, 1000),
            'current_new_canonical_count' => $this->newCount(['id' => $campaign->campaign_id, 'baseline_max_id' => $campaign->baseline_max_id])]);
    }

    public function pause(?string $id = null): array
    {
        if ($id !== null && $id !== $this->ownedId) {
            $this->claim($id);
        }
        DB::transaction(function () {
            $campaign = $this->locked();
            if (! in_array($campaign->status, ['running', 'pausing', 'paused'], true)) {
                throw new PilotStoppedException('terminal_campaign_cannot_pause');
            }
            $campaign->update(['status' => 'paused', 'paused_at' => now(), 'current_new_canonical_count' => $this->newCount()]);
        });

        return $this->state();
    }

    public function resume(?string $id = null): array
    {
        if ($id !== null && $id !== $this->ownedId) {
            $this->claim($id);
        }
        DB::transaction(function () {
            $campaign = $this->locked();
            if ($campaign->status !== 'paused') {
                throw new PilotStoppedException('ordinary_resume_requires_paused_campaign');
            }
            if ($campaign->used_http_requests >= $campaign->max_http_requests || $campaign->selected_source_count >= $campaign->max_source_selections) {
                throw new PilotStoppedException('exhausted_campaign_cannot_resume');
            }
            $campaign->update(['status' => 'running']);
        });

        return $this->state();
    }

    public function complete(): void
    {
        $this->assertOpen();
        DB::transaction(function () {
            $campaign = $this->locked();
            if ($this->newCount() !== $campaign->target_new_canonical || ($campaign->state['audit_count'] ?? null) !== $campaign->target_new_canonical || DB::table('jobs')->where('queue', 'scraping')->exists()) {
                throw new RuntimeException('Completion requires target canonical count, passing final audit and no pending receipts.');
            }
            $campaign->update(['status' => 'completed', 'completed_at' => now(), 'stop_reason' => 'approved_target_reached',
                'current_new_canonical_count' => $campaign->target_new_canonical]);
        });
    }

    public function loadCheckpoint(string $name, array $bounds): ?array
    {
        $this->assertOpen();
        $checkpoint = ScrapeCampaignCheckpoint::whereHas('campaign', fn ($query) => $query->where('campaign_id', $this->ownedId))->where('name', $name)->first();
        if ($checkpoint && $checkpoint->bounds !== $bounds) {
            throw new RuntimeException('Cannot reset durable discovery bounds.');
        }

        return $checkpoint?->state;
    }

    public function saveCheckpoint(string $name, array $bounds, array $state): void
    {
        $this->assertOpen();
        if (! preg_match('/^[a-z0-9-]{1,60}$/D', $name)) {
            throw new InvalidArgumentException('Discovery checkpoint name must be bounded.');
        }
        $error = DB::transaction(function () use ($name, $bounds, $state) {
            $campaign = $this->locked();
            $checkpoint = $campaign->checkpoints()->where('name', $name)->lockForUpdate()->first();
            if ($checkpoint && $checkpoint->bounds !== $bounds) {
                throw new RuntimeException('Cannot reset durable discovery bounds.');
            }
            $nodes = count($state['nodes'] ?? []);
            $ids = [];
            foreach ($state['nodes'] ?? [] as $node) {
                foreach ($node['rows'] ?? [] as $row) {
                    $ids[(string) $row['id']] = true;
                }
            }
            foreach ($campaign->checkpoints()->where('name', '<>', $name)->get() as $other) {
                $nodes += count($other->state['nodes'] ?? []);
                foreach ($other->state['nodes'] ?? [] as $node) {
                    foreach ($node['rows'] ?? [] as $row) {
                        $ids[(string) $row['id']] = true;
                    }
                }
            }
            if ($nodes > $campaign->configuration_snapshot['max_partitions'] || count($ids) > $campaign->configuration_snapshot['max_discovered_ids']) {
                $this->terminal($campaign, 'campaign_discovery_budget_exhausted');

                return 'campaign_discovery_budget_exhausted';
            }
            $values = ['bounds' => $bounds, 'state' => $state, 'owner_generation' => $this->ownerGeneration,
                'version' => ($checkpoint?->version ?? 0) + 1];
            if ($checkpoint) {
                $checkpoint->update($values);
            } else {
                $campaign->checkpoints()->create(['name' => $name, ...$values]);
            }
            $summary = $campaign->state;
            $summary['discovery_partition_count'] = $nodes;
            $summary['discovered_source_count'] = count($ids);
            $campaign->update(['state' => $summary]);

            return null;
        });
        if ($error !== null) {
            throw new PilotStoppedException($error);
        }
    }
}
