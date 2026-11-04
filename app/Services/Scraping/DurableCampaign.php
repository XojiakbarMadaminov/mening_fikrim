<?php

namespace App\Services\Scraping;

use App\Jobs\ImportDocumentJob;
use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentSource;
use App\Models\ScrapeCampaign;
use App\Models\ScrapeCampaignCheckpoint;
use App\Models\ScrapeRun;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexRequestException;
use App\Services\Reporting\CampaignReportingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;

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

    private bool $transportProbe = false;

    public function exists(): bool
    {
        return Schema::hasTable('scrape_campaigns') && ScrapeCampaign::exists();
    }

    public function hasLiveOwner(string $id): bool
    {
        $row = ScrapeCampaign::where('campaign_id', $id)->firstOrFail();

        return $row->owner_token !== null && $row->owner_backend_pid !== null && DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND classid=?::oid AND objid=?::oid AND pid=? AND granted) AS held", [self::LOCK_NAMESPACE, self::LOCK_KEY, $row->owner_backend_pid])->held;
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
        // The larger ceiling is available only through the exact operator-approved profile.
        // Generic start/validation and historical pilots retain their original caps.
        $profile = config('corpus_campaigns.'.$id);
        $extended = ($profile['extended_collection'] ?? false) === true
            && ($configuration['autonomous'] ?? false) === true
            && ($configuration['campaign_type'] ?? null) === 'corpus_collection'
            && $requests === $profile['requests'] && $sources === $profile['sources'] && $target === $profile['target']
            && ($configuration['scope'] ?? null) === $profile['scope']
            && ($configuration['stages'] ?? null) === $profile['stages']
            && ($configuration['authorized_scope_successor'] ?? null) === $profile['predecessor']
            && collect($profile['limits'])->every(fn ($value, $key) => ($configuration[$key] ?? null) === $value);
        $large = $extended && $id === 'corpus-30000-20261030';
        if (! preg_match('/^[a-z0-9-]{1,60}$/D', $id) || $requests < 1 || $requests > ($large ? 360000 : ($extended ? 120000 : 15000)) || $sources < 1 || $sources > ($large ? 75000 : ($extended ? 25000 : 3500)) || $target < 0 || $target > ($large ? 30000 : ($extended ? 10000 : 1000)) || ($target === 0 && ($configuration['campaign_type'] ?? null) !== 'validation')) {
            throw new InvalidArgumentException('Durable pilot caps: unique ID, 1..15000 requests, 1..3500 source selections and 1..1000 new canonical documents.');
        }
        $stages = array_values(array_filter([30, 100, 300, 600, 1000], fn ($stage) => $stage < $target));
        $stages[] = $target;
        if (isset($configuration['stages'])) {
            $stages = $configuration['stages'];
            if (! is_array($stages) || $stages === [] || count($stages) > 10 || end($stages) !== $target
                || $stages !== array_values(array_unique($stages)) || collect($stages)->contains(fn ($stage) => ! is_int($stage) || $stage < 1 || $stage > $target)
                || collect($stages)->sliding(2)->contains(fn ($pair) => $pair->last() <= $pair->first())) {
                throw new InvalidArgumentException('Stages must be strictly increasing integer canonical targets ending at the campaign target.');
            }
        }
        $configuration = array_replace(['workers' => 1, 'request_interval_ms' => 2000, 'per_context_request_limit' => 400,
            'max_partitions' => 255, 'max_discovered_ids' => 20000, 'max_partition_depth' => 10,
            'page_cap' => 8, 'max_campaign_seconds' => 43200], $configuration);
        if (($configuration['single_day_page_cap'] ?? 8) < 1 || ($configuration['single_day_page_cap'] ?? 8) > ($large ? 64 : 8)
            || ($configuration['max_request_contexts'] ?? 1024) < 1 || ($configuration['max_request_contexts'] ?? 1024) > ($large ? 16384 : 1024)
            || (! $large && isset($configuration['discovery_gap_policy'])) || $configuration['workers'] !== 1 || $configuration['request_interval_ms'] < 2000 || $configuration['per_context_request_limit'] < 1 || $configuration['per_context_request_limit'] > 400
            || $configuration['max_partitions'] < 1 || $configuration['max_partitions'] > ($large ? 8192 : 1024) || $configuration['max_discovered_ids'] < 1 || $configuration['max_discovered_ids'] > ($large ? 500000 : 100000)
            || $configuration['max_partition_depth'] < 0 || $configuration['max_partition_depth'] > 16 || $configuration['page_cap'] < 1 || $configuration['page_cap'] > 8
            || $configuration['max_campaign_seconds'] < 1 || $configuration['max_campaign_seconds'] > ($large ? 1814400 : ($extended ? 604800 : 86400))) {
            throw new InvalidArgumentException('Campaign safety configuration must remain finite and conservative.');
        }
        $this->obtainLock();
        try {
            DB::transaction(function () use ($id, $requests, $sources, $target, $stages, $configuration, $large) {
                $previous = $this->latest();
                $successor = ($configuration['authorized_scope_successor'] ?? null) === $previous?->campaign_id
                    && $previous?->status === 'stopped'
                    && ($previous?->stop_reason === 'bounded_discovery_scope_exhausted'
                        || ($large && $previous?->stop_reason === 'unreconciled_discovery_partition' && in_array($previous?->stop_reason, config('corpus_campaigns.'.$id.'.successor_stop_reasons', []), true)))
                    && ($profile = config('corpus_campaigns.'.$id)) !== null
                    && $profile['predecessor'] === $previous->campaign_id && $requests === $profile['requests']
                    && $sources === $profile['sources'] && $target === $profile['target'] && $stages === $profile['stages']
                    && ($configuration['scope'] ?? null) === $profile['scope'] && ($configuration['autonomous'] ?? false) === true;
                if ($previous && $previous->status !== 'completed' && ! $successor) {
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
                    'started_at' => now(), 'status' => ($configuration['prepare_only'] ?? false) ? 'paused' : 'running',
                    'configuration_snapshot' => $configuration, 'campaign_type' => $configuration['campaign_type'] ?? 'corpus_pilot',
                    'last_request_started_at' => $previous?->last_request_started_at,
                    'state' => ['stages' => $stages, 'selected_ids' => [], 'contexts' => [], 'audit_count' => null,
                        'consecutive_errors' => 0, 'http_status_counts' => [], 'discovery_partition_count' => 0, 'discovered_source_count' => 0,
                        'prepared' => (bool) ($configuration['prepare_only'] ?? false),
                        'predecessor_record_sha256' => $previous === null ? null : hash('sha256', json_encode($previous->getRawOriginal())),
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
            throw new PilotStoppedException($state['stopped']['reason'] ?? 'campaign_'.$state['status'], in_array($state['status'], ['pausing', 'paused'], true) ? 10 : 3600);
        }
        $this->assertOwner();
        if (now()->diffInSeconds($state['started_at'], true) >= $state['configuration_snapshot']['max_campaign_seconds']) {
            $this->stop('campaign_duration_budget_exhausted');
            throw new PilotStoppedException('campaign_duration_budget_exhausted');
        }
        if (($state['transport_wait']['active'] ?? false) && ! $this->transportProbe) {
            throw new PilotStoppedException('transport_cooldown', max(1, (int) now()->diffInSeconds(Carbon::parse($state['transport_wait']['next_probe_at']), false)));
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
    public function secondsUntilNextRequest(bool $afterCacheLoss = false): float
    {
        $this->assertOwner();
        $row = DB::selectOne('SELECT EXTRACT(EPOCH FROM (last_request_started_at - clock_timestamp())) AS seconds FROM scrape_campaigns WHERE campaign_id = ?', [$this->ownedId]);
        if ($row->seconds === null) {
            return 0.0;
        }
        $interval = max(2000, $this->state()['configuration_snapshot']['request_interval_ms']) / 1000;

        $wait = max(0, $interval + (float) $row->seconds);
        // A DB connection/process can disappear while its HTTP request is still in flight.
        // Recovery waits out the bounded HTTP timeout before another owner sends traffic.
        if (($this->state()['http_in_flight'] ?? false) === true) {
            $wait = max($wait, (float) $row->seconds + (float) $this->state()['http_recovery_wait_seconds']);
        }
        if ($afterCacheLoss && ($finished = $this->state()['last_response_at'] ?? null)) {
            $since = DB::selectOne('SELECT EXTRACT(EPOCH FROM (?::timestamptz - clock_timestamp())) AS seconds', [$finished]);
            $wait = max($wait, $interval + (float) $since->seconds);
        }

        return max(0, $wait);
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
            if (! isset($state['contexts'][$context]) && count($state['contexts']) >= ($campaign->configuration_snapshot['max_request_contexts'] ?? 1024)) {
                $this->terminal($campaign, 'request_context_budget_exhausted');

                return 'request_context_budget_exhausted';
            }
            $state['contexts'][$context] = ($state['contexts'][$context] ?? 0) + 1;
            $state['last_reservation'] = ['context' => $context, 'method' => $method, 'source_path' => parse_url($url, PHP_URL_PATH)];
            if ($method === 'GET' && $this->validTransportPath($state['last_reservation']['source_path'])) {
                $state['last_observed_get_path'] = $state['last_reservation']['source_path'];
            }
            $state['http_in_flight'] = true;
            $state['http_recovery_wait_seconds'] = max((int) config('scraping.http_timeout'), (int) config('scraping.pdf_timeout')) + 30;
            $campaign->update(['used_http_requests' => $campaign->used_http_requests + 1, 'state' => $state]);
            // Query builder avoids passing an SQL expression through Eloquent's datetime cast.
            DB::table('scrape_campaigns')->where('id', $campaign->id)->update(['last_request_started_at' => DB::raw('clock_timestamp()')]);

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
            $state['last_response_at'] = DB::selectOne('SELECT clock_timestamp() AS at')->at;
            $state['http_in_flight'] = false;
            $state['consecutive_errors'] = $status === 0 || $status >= 500 ? $state['consecutive_errors'] + 1 : 0;
            $state['transport_error_streak'] = $status === 0 ? ($state['transport_error_streak'] ?? 0) + 1 : 0;
            if ($status === 0 && ($state['transport_wait']['enabled'] ?? false) && $campaign->campaign_id === 'corpus-30000-20261030') {
                if (! $this->transportProbe && $state['transport_error_streak'] >= 2 && ! ($state['transport_wait']['active'] ?? false)) {
                    $wait = $state['transport_wait'];
                    $path = $state['last_observed_get_path'] ?? null;
                    if ($wait['episodes'] >= 12 || ! $this->validTransportPath($path)) {
                        $campaign->update(['state' => $state]);
                        $this->terminal($campaign, 'transport_episode_budget_exhausted_or_no_probe_path');

                        return;
                    }
                    $state['transport_wait'] = [...$wait, 'active' => true, 'episodes' => $wait['episodes'] + 1,
                        'probe_attempts' => 0, 'source_path' => $path, 'started_at' => now()->toAtomString(),
                        'next_probe_at' => now()->addMinutes(15)->toAtomString()];
                }
                $campaign->update(['state' => $state]);

                return; // Only opted-in HTTP0 uses a circuit; access/5xx remain terminal below.
            }
            $campaign->update(['state' => $state]);
            if (in_array($status, [403, 429], true) || $state['consecutive_errors'] >= 2) {
                $this->terminal($campaign, 'source_http_'.$status.($state['consecutive_errors'] >= 2 ? '_repeated' : ''));
            }
        });
    }

    private function validTransportPath(mixed $path): bool
    {
        return is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//')
            && strlen($path) <= 2048 && strpbrk($path, "?#\r\n") === false;
    }

    public function transportProbeActive(): bool
    {
        return $this->transportProbe;
    }

    /** Explicit operator opt-in; no HTTP, counter reset, receipt changes or implicit terminal recovery. */
    public function enableTransportWait(string $id): array
    {
        $this->claim($id);
        try {
            DB::transaction(function () use ($id) {
                $row = $this->locked();
                $state = $row->state;
                if ($id !== 'corpus-30000-20261030' || ! in_array($row->status, ['paused', 'running'], true)
                    || ($state['transport_wait']['enabled'] ?? false) || ($state['http_in_flight'] ?? false)
                    || ($state['http_status_counts']['403'] ?? 0) || ($state['http_status_counts']['429'] ?? 0)
                    || $row->used_http_requests >= $row->max_http_requests || $row->selected_source_count >= $row->max_source_selections
                    || now()->diffInSeconds($row->started_at, true) >= $row->configuration_snapshot['max_campaign_seconds']) {
                    throw new RuntimeException('Transport wait requires the exact eligible campaign and remaining original budgets; it cannot reopen a terminal stop.');
                }
                $baseline = $row->checkpoints()->where('name', 'preservation-baseline')->firstOrFail()->state['baseline'];
                $audit = app(CampaignIntegrity::class)->audit($baseline, $id);
                if ($audit['errors'] !== []) {
                    throw new RuntimeException('Transport policy integrity audit failed: '.implode(',', $audit['errors']));
                }
                $adopt = ($state['network_probe_required'] ?? false) === true;
                $path = $adopt ? ($state['network_probe_path'] ?? null) : ($state['last_observed_get_path'] ?? $state['last_reservation']['source_path'] ?? null);
                if ($adopt && ! $this->validTransportPath($path)) {
                    throw new RuntimeException('A previously reviewed network probe path is required.');
                }
                $policy = ['enabled' => true, 'version' => 1, 'active' => $adopt, 'episodes' => $adopt ? 1 : 0,
                    'max_episodes' => 12, 'max_probes_per_episode' => 8, 'probe_attempts' => 0, 'total_probes' => 0,
                    'source_path' => $path, 'next_probe_at' => $adopt ? now()->toAtomString() : null,
                    'enabled_at' => now()->toAtomString()];
                $row->checkpoints()->create(['name' => 'transport-wait-policy', 'bounds' => ['explicit-transport-wait-v1'],
                    'owner_generation' => $row->owner_generation, 'state' => ['before' => $row->getRawOriginal(), 'policy' => $policy, 'audit' => $audit]]);
                $state['transport_wait'] = $policy;
                if ($adopt) {
                    $state['network_probe_required'] = false; // The persistent circuit now fences all traffic until its probe succeeds.
                }
                $row->update(['state' => $state]);
            });

            return $this->state($id);
        } finally {
            $this->release();
        }
    }

    /** Controller owns this one paced probe; worker/normal HTTP cannot pass an open circuit. */
    public function serviceTransportWait(): bool
    {
        $state = $this->state();
        if (! ($state['transport_wait']['active'] ?? false)) {
            return false;
        }
        $this->transportProbe = true;
        try {
            $state = $this->assertOpen(); // Deadline/ownership are checked even while waiting without traffic.
            $wait = $state['transport_wait'];
            if (Carbon::parse($wait['next_probe_at'])->isFuture()) {
                return true;
            }
            $baseline = ScrapeCampaign::where('campaign_id', $this->ownedId)->firstOrFail()->checkpoints()->where('name', 'preservation-baseline')->firstOrFail()->state['baseline'];
            $audit = app(CampaignIntegrity::class)->audit($baseline, $this->ownedId);
            if ($audit['errors'] !== []) {
                $this->stop('stage_integrity_audit_failed');
                throw new PilotStoppedException('stage_integrity_audit_failed');
            }
            if ($wait['probe_attempts'] >= 8 || $wait['episodes'] > 12 || ! $this->validTransportPath($wait['source_path'])) {
                $this->stop('transport_probe_budget_exhausted');
                throw new PilotStoppedException('transport_probe_budget_exhausted');
            }
            DB::transaction(function () {
                $row = $this->locked();
                $state = $row->state;
                $state['transport_wait']['probe_attempts']++;
                $state['transport_wait']['total_probes']++;
                // Persist the next boundary BEFORE sending: a crash burns a probe and cannot send again immediately.
                $delay = min(3600, 900 * 2 ** min(2, $state['transport_wait']['probe_attempts']));
                $state['transport_wait']['next_probe_at'] = now()->addSeconds($delay)->toAtomString();
                $row->update(['state' => $state]);
            });
            $attempt = $wait['probe_attempts'] + 1;
            $client = app(LexHttpClient::class);
            $client->context('transport-probe-'.$wait['episodes'].'-'.$attempt);
            try {
                $status = $client->get('https://lex.uz'.$wait['source_path'])->status();
            } catch (LexRequestException $e) {
                $status = $e->status;
                if ($this->state()['status'] !== 'running') {
                    throw $e;
                }
            }
            $success = in_array($status, [200, 404], true);
            $this->saveCheckpoint('transport-probe-'.$wait['episodes'].'-'.$attempt, ['paced-get-v1'], [
                'source_path' => $wait['source_path'], 'status' => $status, 'transport_reachable' => $success,
                'checked_at' => now()->toAtomString(), 'audit' => $audit]);
            DB::transaction(function () use ($success, $status, $attempt) {
                $row = $this->locked();
                $state = $row->state;
                $state['transport_wait']['last_probe_status'] = $status;
                if ($success) {
                    $state['transport_wait']['active'] = false;
                    $state['transport_wait']['next_probe_at'] = null;
                    $state['transport_wait']['resumed_at'] = now()->toAtomString();
                }
                $row->update(['state' => $state]);
                if (! $success && ($status !== 0 || $attempt >= 8)) {
                    $this->terminal($row, $status === 0 ? 'transport_probe_budget_exhausted' : 'transport_probe_http_'.$status);
                }
            });

            return true;
        } finally {
            $this->transportProbe = false;
        }
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

    /** A crashed worker's reserved receipt remains protected until database retry_after. */
    public function queueDelaySeconds(): int
    {
        $this->assertOpen();
        $row = DB::selectOne("SELECT MIN(GREATEST(available_at, COALESCE(reserved_at + ?::integer, available_at))) AS ready_at FROM jobs WHERE queue = 'scraping'",
            [(int) config('queue.connections.database.retry_after')]);

        return $row->ready_at === null ? 0 : max(0, (int) $row->ready_at - now()->timestamp);
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

    /** Operator signal only; the owned controller acknowledges without changing budget or receipts. */
    public function requestPause(string $id): array
    {
        DB::transaction(function () use ($id) {
            $campaign = ScrapeCampaign::where('campaign_id', $id)->lockForUpdate()->firstOrFail();
            if ($this->id() !== $id || ! in_array($campaign->status, ['running', 'pausing', 'paused'], true)) {
                throw new PilotStoppedException('terminal_campaign_cannot_pause');
            }
            if ($campaign->status === 'running') {
                $campaign->update(['status' => 'pausing']);
            }
        });

        return $this->state($id);
    }

    /** Operator signal, acknowledged by the current exclusive owner at a safe boundary. */
    public function requestStop(string $id, string $reason): void
    {
        DB::transaction(function () use ($id, $reason) {
            $campaign = ScrapeCampaign::where('campaign_id', $id)->lockForUpdate()->firstOrFail();
            if ($this->id() !== $id || ! in_array($campaign->status, ['running', 'pausing', 'paused'], true)) {
                throw new PilotStoppedException('terminal_campaign_cannot_stop_again');
            }
            $state = $campaign->state;
            $state['operator_stop_requested'] = mb_substr($reason, 0, 200);
            $campaign->update(['status' => 'pausing', 'state' => $state]);
        });
    }

    public function saveControl(array $control): void
    {
        DB::transaction(function () use ($control) {
            $campaign = $this->locked();
            $state = $campaign->state;
            $state['control'] = $control;
            $campaign->update(['state' => $state]);
        });
    }

    public function recordFinalReport(array $report): void
    {
        $this->assertOwner();
        $row = ScrapeCampaign::where('campaign_id', $this->ownedId)->firstOrFail();
        if (! in_array($row->status, ['stopped', 'completed', 'failed'], true) || ($report['campaign_id'] ?? null) !== $row->campaign_id) {
            throw new RuntimeException('Final report requires the owned terminal campaign.');
        }
        $row->checkpoints()->firstOrCreate(['name' => $row->finalReportName()], ['bounds' => ['report-v1'], 'state' => ['report' => $report], 'owner_generation' => $row->owner_generation]);
    }

    public function acknowledgeSignals(): bool
    {
        $state = $this->state();
        if ($state['status'] === 'pausing') {
            if (isset($state['operator_stop_requested'])) {
                $this->stop('operator_stop:'.$state['operator_stop_requested']);
            } else {
                $this->pause();
            }

            return true;
        }

        return $state['status'] !== 'running';
    }

    /** Explicit operator recovery of one already-resolved 404, never an automatic safety bypass. */
    public function recoverNotFound(string $id, int $failedJobId): array
    {
        $this->obtainLock();
        try {
            DB::transaction(function () use ($id, $failedJobId) {
                $campaign = ScrapeCampaign::where('campaign_id', $id)->lockForUpdate()->firstOrFail();
                if ($id !== 'corpus-30000-20261030' || $this->id() !== $id || $campaign->status !== 'stopped'
                    || $campaign->stop_reason !== 'stage_integrity_audit_failed' || $failedJobId < 1
                    || ($campaign->state['recovery_count'] ?? 0) >= 10) {
                    throw new RuntimeException('Only the explicitly reviewed 30,000 campaign audit stop is recoverable.');
                }
                if ($this->hasLiveOwner($id) || DB::table('jobs')->where('queue', 'scraping')->exists()
                    || $campaign->used_http_requests >= $campaign->max_http_requests || $campaign->selected_source_count >= $campaign->max_source_selections
                    || now()->diffInSeconds($campaign->started_at, true) >= $campaign->configuration_snapshot['max_campaign_seconds']) {
                    throw new RuntimeException('Recovery requires settled work and remaining original budgets.');
                }
                $failure = DB::table('failed_jobs')->where('id', $failedJobId)->first();
                $payload = $failure ? json_decode($failure->payload, true, flags: JSON_THROW_ON_ERROR) : [];
                if (! $failure || $failure->queue !== 'scraping'
                    || ! str_starts_with($failure->exception, LexRequestException::class.': ')
                    || ! str_contains(strtok($failure->exception, "\n"), '(HTTP 404)')
                    || ($payload['data']['commandName'] ?? null) !== ImportDocumentJob::class
                    || ! preg_match('/^App\\\\Jobs\\\\ImportDocumentJob#(\d+)$/D', $payload['displayName'] ?? '', $match)
                    || ! preg_match('/s:9:"candidate";s:\d+:"(-?\d+)";/', $payload['data']['command'] ?? '', $candidate)) {
                    throw new RuntimeException('Failed job is not an eligible document-fetch HTTP 404.');
                }
                $runId = (int) $match[1];
                $run = ScrapeRun::whereKey($runId)->where('checkpoint->campaign_id', $id)->firstOrFail();
                $item = $run->items()->where('source_external_id', $candidate[1])->firstOrFail();
                if (! $item->terminal() || ! in_array($item->status, ['imported', 'unchanged', 'review', 'skipped_alias'], true)
                    || ! $item->document_id || ($item->outcome['identity_verified'] ?? false) !== true
                    || ($item->outcome['persistence_complete'] ?? false) !== true
                    || ($item->outcome['technical_retry_pending'] ?? false)
                    || ! DocumentContent::where('document_id', $item->document_id)->exists()) {
                    throw new RuntimeException('404 item must already have resolved identity and persisted usable content.');
                }
                if ($campaign->checkpoints()->where('name', $campaign->finalReportName())->doesntExist()) {
                    throw new RuntimeException('Preserve the original terminal snapshot before recovery.');
                }
                $before = $campaign->getRawOriginal();
                $state = $campaign->state;
                if (isset($state['acknowledged_failed_jobs'][(string) $failedJobId])) {
                    throw new RuntimeException('This failed job was already acknowledged; no repeat recovery.');
                }
                $state['recovery_count'] = ($state['recovery_count'] ?? 0) + 1;
                $state['acknowledged_failed_jobs'][(string) $failedJobId] = [
                    'sha256' => hash('sha256', json_encode($failure)), 'resolved_item_id' => $item->id,
                    'source_external_id' => $item->source_external_id, 'checked_at' => now()->toAtomString(),
                ];
                $campaign->update(['state' => $state]);
                $baseline = $campaign->checkpoints()->where('name', 'preservation-baseline')->firstOrFail()->state['baseline'];
                $audit = app(CampaignIntegrity::class)->audit($baseline, $id);
                if ($audit['errors'] !== [] || $audit['pending_jobs'] !== 0) {
                    throw new RuntimeException('Recovery integrity audit failed: '.implode(',', $audit['errors']));
                }
                $campaign->checkpoints()->create(['name' => 'operator-recovery-'.$state['recovery_count'],
                    'bounds' => ['explicit-resolved-404-v1'], 'owner_generation' => $campaign->owner_generation,
                    'state' => ['authorized_action' => 'recover-not-found', 'failed_job_id' => $failedJobId,
                        'before' => $before, 'audit' => $audit, 'recovered_at' => now()->toAtomString()]]);
                // Pause first: the operator then uses ordinary resume; elapsed/request/source budgets persist.
                $campaign->update(['status' => 'paused', 'paused_at' => now(), 'stopped_at' => null, 'stop_reason' => null,
                    'owner_token' => null, 'owner_backend_pid' => null, 'owner_claimed_at' => null]);
            });

            return $this->state($id);
        } finally {
            $this->unlock();
        }
    }

    /** Repair-only operator recovery for the named campaign's missing native OCR dependency. */
    public function recoverOcr(string $id, array $failureIds): array
    {
        $health = app(ScrapingDependencyHealth::class)->assertReady();
        if ($failureIds === [] || count($failureIds) > 30 || count($failureIds) !== count(array_unique($failureIds))
            || array_filter($failureIds, fn ($value) => ! is_int($value) || $value < 1)) {
            throw new RuntimeException('Explicit unique OCR failed-job IDs (1..30) are required.');
        }
        $this->obtainLock();
        try {
            DB::transaction(function () use ($id, $failureIds, $health) {
                $campaign = ScrapeCampaign::where('campaign_id', $id)->lockForUpdate()->firstOrFail();
                $state = $campaign->state;
                if ($id !== 'corpus-30000-20261030' || $this->id() !== $id || $campaign->status !== 'stopped'
                    || ! in_array($campaign->stop_reason, ['stage_integrity_audit_failed', 'runtime_dependency_unavailable'], true)
                    || ($state['recovery_count'] ?? 0) >= 10 || $this->hasLiveOwner($id)
                    || ($state['http_status_counts']['403'] ?? 0) > 0 || ($state['http_status_counts']['429'] ?? 0) > 0
                    || DB::table('jobs')->where('queue', 'scraping')->exists()
                    || $campaign->used_http_requests >= $campaign->max_http_requests
                    || $campaign->selected_source_count >= $campaign->max_source_selections
                    || now()->diffInSeconds($campaign->started_at, true) >= $campaign->configuration_snapshot['max_campaign_seconds']) {
                    throw new RuntimeException('OCR recovery requires the reviewed stopped campaign, settled queue and original remaining budgets.');
                }
                if ($campaign->checkpoints()->where('name', $campaign->finalReportName())->doesntExist()) {
                    throw new RuntimeException('Original epoch terminal snapshot must be preserved.');
                }
                $before = $campaign->getRawOriginal();
                $evidence = [];
                $affected = [];
                foreach ($failureIds as $failureId) {
                    $failure = DB::table('failed_jobs')->where('id', $failureId)->first();
                    $payload = $failure ? json_decode($failure->payload, true, flags: JSON_THROW_ON_ERROR) : [];
                    if (! $failure || $failure->queue !== 'scraping'
                        || ! str_starts_with($failure->exception, ProcessFailedException::class.': ')
                        || ! str_contains($failure->exception, 'tesseract') || ! str_contains($failure->exception, 'Exit Code: 127')
                        || ! str_contains($failure->exception, 'not found')
                        || ($payload['data']['commandName'] ?? null) !== ImportDocumentJob::class
                        || ! preg_match('/^App\\\\Jobs\\\\ImportDocumentJob#(\d+)$/D', $payload['displayName'] ?? '', $runMatch)
                        || ! preg_match('/s:9:"candidate";s:\d+:"(-?\d+)";/', $payload['data']['command'] ?? '', $sourceMatch)
                        || isset($state['acknowledged_failed_jobs'][(string) $failureId])) {
                        throw new RuntimeException('Only explicitly reviewed missing-Tesseract failures may be acknowledged.');
                    }
                    $run = ScrapeRun::whereKey((int) $runMatch[1])->where('checkpoint->campaign_id', $id)->lockForUpdate()->firstOrFail();
                    $item = $run->items()->where('source_external_id', $sourceMatch[1])->lockForUpdate()->firstOrFail();
                    $affected[$item->id] = $item;
                    $evidence[(string) $failureId] = ['sha256' => hash('sha256', json_encode($failure)),
                        'source_external_id' => $item->source_external_id, 'item_id' => $item->id,
                        'reason' => 'missing_tesseract_repaired', 'checked_at' => now()->toAtomString()];
                }
                $state['recovery_count'] = ($state['recovery_count'] ?? 0) + 1;
                $state['acknowledged_failed_jobs'] = ($state['acknowledged_failed_jobs'] ?? []) + $evidence;
                $itemBefore = [];
                $requeued = [];
                foreach ($affected as $item) {
                    if ($item->status !== 'failed') {
                        continue; // Completed/review/alias sources must not be imported again.
                    }
                    $itemBefore[(string) $item->id] = $item->getRawOriginal();
                    app(RunItemTracker::class)->reopenFailed($item, $state['recovery_count']);
                    $run = $item->run;
                    $checkpoint = $run->checkpoint;
                    $checkpoint['jobs_reconciled'] = false;
                    $run->update(['status' => 'running', 'finished_at' => null, 'checkpoint' => $checkpoint]);
                    $requeued[] = $item->id;
                }
                if ($requeued === []) {
                    throw new RuntimeException('No failed OCR item requires explicit recovery.');
                }
                $campaign->update(['state' => $state]);
                $baseline = $campaign->checkpoints()->where('name', 'preservation-baseline')->firstOrFail()->state['baseline'];
                $audit = app(CampaignIntegrity::class)->audit($baseline, $id);
                if ($audit['errors'] !== []) {
                    throw new RuntimeException('OCR recovery integrity audit failed: '.implode(',', $audit['errors']));
                }
                $campaign->checkpoints()->create(['name' => 'operator-recovery-'.$state['recovery_count'],
                    'bounds' => ['explicit-ocr-repair-v1'], 'owner_generation' => $campaign->owner_generation,
                    'state' => ['authorized_action' => 'recover-ocr', 'before' => $before, 'item_before' => $itemBefore,
                        'acknowledged_failures' => $evidence, 'requeued_item_ids' => $requeued,
                        'health' => $health, 'audit' => $audit, 'recovered_at' => now()->toAtomString()]]);
                $campaign->update(['status' => 'paused', 'paused_at' => now(), 'stopped_at' => null, 'stop_reason' => null,
                    'owner_token' => null, 'owner_backend_pid' => null, 'owner_claimed_at' => null]);
            });

            return $this->state($id);
        } finally {
            $this->unlock();
        }
    }

    /** Explicit, cooled-off operator recovery of a transport-only stop; receipts are retained. */
    public function recoverNetwork(string $id): array
    {
        $this->obtainLock();
        try {
            DB::transaction(function () use ($id) {
                $campaign = ScrapeCampaign::where('campaign_id', $id)->lockForUpdate()->firstOrFail();
                $state = $campaign->state;
                if ($id !== 'corpus-30000-20261030' || $this->id() !== $id || $campaign->status !== 'stopped'
                    || $campaign->stop_reason !== 'source_http_0_repeated' || ($state['consecutive_errors'] ?? 0) < 2
                    || ($state['http_status_counts']['403'] ?? 0) > 0 || ($state['http_status_counts']['429'] ?? 0) > 0
                    || ($state['http_in_flight'] ?? false) || ($state['network_recovery_count'] ?? 0) >= 3
                    || ($state['recovery_count'] ?? 0) >= 10) {
                    throw new RuntimeException('Only a reviewed transport-only stop without access restrictions is recoverable.');
                }
                if (! $campaign->stopped_at || $campaign->stopped_at->isFuture() || $campaign->stopped_at->diffInSeconds(now()) < 900) {
                    throw new RuntimeException('Network recovery requires at least 15 minutes of cooldown.');
                }
                $last = $state['last_reservation'] ?? [];
                $path = $last['source_path'] ?? '';
                if (($last['method'] ?? null) !== 'GET' || ! is_string($path) || ! str_starts_with($path, '/')
                    || str_starts_with($path, '//') || strlen($path) > 2048 || strpbrk($path, "?#\r\n") !== false) {
                    throw new RuntimeException('A previously observed bounded GET path is required for the network probe.');
                }
                if ($this->hasLiveOwner($id) || $campaign->used_http_requests >= $campaign->max_http_requests
                    || $campaign->selected_source_count >= $campaign->max_source_selections
                    || now()->diffInSeconds($campaign->started_at, true) >= $campaign->configuration_snapshot['max_campaign_seconds']) {
                    throw new RuntimeException('Recovery requires released ownership and remaining original budgets.');
                }
                $receipts = [];
                foreach (DB::table('jobs')->where('queue', 'scraping')->orderBy('id')->get() as $job) {
                    $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
                    if (($job->reserved_at !== null && $job->reserved_at + config('queue.connections.database.retry_after') > now()->timestamp)
                        || ! preg_match('/^App\\\\Jobs\\\\(?:ImportDocumentJob|DiscoverDocumentsJob)#(\d+)$/D', $payload['displayName'] ?? '', $match)
                        || ! ScrapeRun::whereKey((int) $match[1])->where('checkpoint->campaign_id', $id)->exists()) {
                        throw new RuntimeException('Network recovery refuses live or foreign queue receipts.');
                    }
                    $receipts[(string) $job->id] = hash('sha256', json_encode($job));
                }
                if ($campaign->checkpoints()->where('name', $campaign->finalReportName())->doesntExist()) {
                    throw new RuntimeException('Original terminal snapshot must exist before recovery.');
                }
                $baseline = $campaign->checkpoints()->where('name', 'preservation-baseline')->firstOrFail()->state['baseline'];
                $audit = app(CampaignIntegrity::class)->audit($baseline, $id);
                if ($audit['errors'] !== []) {
                    throw new RuntimeException('Network recovery integrity audit failed: '.implode(',', $audit['errors']));
                }
                $before = $campaign->getRawOriginal();
                $state['recovery_count'] = ($state['recovery_count'] ?? 0) + 1;
                $state['network_recovery_count'] = ($state['network_recovery_count'] ?? 0) + 1;
                $state['network_probe_required'] = true;
                $state['network_probe_path'] = $path;
                $state['consecutive_errors'] = 0; // New explicit attempt; historical error totals remain spent.
                $campaign->checkpoints()->create(['name' => 'operator-recovery-'.$state['recovery_count'],
                    'bounds' => ['explicit-network-recovery-v1'], 'owner_generation' => $campaign->owner_generation,
                    'state' => ['authorized_action' => 'recover-network', 'before' => $before, 'audit' => $audit,
                        'pending_receipt_hashes' => $receipts, 'recovered_at' => now()->toAtomString()]]);
                $campaign->update(['status' => 'paused', 'paused_at' => now(), 'stopped_at' => null, 'stop_reason' => null,
                    'state' => $state, 'owner_token' => null, 'owner_backend_pid' => null, 'owner_claimed_at' => null]);
            });

            return $this->state($id);
        } finally {
            $this->unlock();
        }
    }

    /** One source-observed path through the original paced/budgeted HTTP client before actors restart. */
    public function probeNetwork(string $id): array
    {
        $pending = $this->state($id);
        if ($id !== 'corpus-30000-20261030' || ! ($pending['network_probe_required'] ?? false)
            || ! in_array($pending['status'], ['paused', 'running'], true)) {
            throw new RuntimeException('No reviewed network recovery requires a probe.');
        }
        $this->claim($id);
        try {
            $state = $this->state();
            if ($id !== 'corpus-30000-20261030' || ($state['network_probe_required'] ?? false) !== true) {
                throw new RuntimeException('No reviewed network recovery requires a probe.');
            }
            if ($state['status'] === 'paused') {
                $this->resume();
            }
            $this->assertOpen();
            $client = app(LexHttpClient::class);
            $client->context('operator-network-probe-'.$state['recovery_count']);
            try {
                $response = $client->get('https://lex.uz'.$state['network_probe_path']);
                $status = $response->status();
            } catch (LexRequestException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
                $status = 404; // Transport is reachable; this does NOT assert language/document absence.
            }
            $this->saveCheckpoint('network-probe-'.$state['recovery_count'], ['paced-source-observed-get-v1'], [
                'source_path' => $state['network_probe_path'], 'http_status' => $status,
                'transport_reachable' => true, 'checked_at' => now()->toAtomString(),
            ]);
            DB::transaction(function () {
                $campaign = $this->locked();
                $state = $campaign->state;
                $state['network_probe_required'] = false;
                $campaign->update(['state' => $state]);
            });

            return $this->state();
        } catch (\Throwable $e) {
            if ($this->state()['status'] === 'running') {
                $this->stop('network_probe_failed');
            }
            if (in_array($this->state()['status'], ['stopped', 'failed', 'completed'], true)) {
                $this->recordFinalReport(app(CampaignReportingService::class)->campaign($id));
            }
            throw $e;
        } finally {
            if ($this->state()['status'] === 'pausing') {
                $this->acknowledgeSignals();
            }
            $this->release();
        }
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
            $state = $campaign->state;
            $values = ['status' => 'running'];
            if ($state['prepared'] ?? false) {
                if ($campaign->used_http_requests !== 0 || $campaign->selected_source_count !== 0) {
                    throw new RuntimeException('Prepared campaign cannot reset an existing execution window.');
                }
                $state['prepared'] = false;
                $values += ['started_at' => now(), 'state' => $state];
            }
            $campaign->update($values);
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
        if ($name === 'preservation-baseline' && ($this->state()['prepared'] ?? false) && $this->state()['status'] === 'paused') {
            $this->assertOwner();
        } else {
            $this->assertOpen();
        }
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
            // Count on PostgreSQL and fetch only distinct IDs, not all historical page trees.
            // The current unsaved checkpoint is combined with the OTHER persisted checkpoints.
            $nodes += (int) DB::selectOne("SELECT count(*) AS n FROM scrape_campaign_checkpoints cp
                CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(cp.state->'nodes') = 'object' THEN cp.state->'nodes' ELSE '{}'::jsonb END) n
                WHERE cp.scrape_campaign_id = ? AND cp.name <> ?", [$campaign->id, $name])->n;
            foreach (DB::cursor("SELECT DISTINCT r.value->>'id' AS id FROM scrape_campaign_checkpoints cp
                CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(cp.state->'nodes') = 'object' THEN cp.state->'nodes' ELSE '{}'::jsonb END) n
                CROSS JOIN LATERAL jsonb_each(CASE WHEN jsonb_typeof(n.value->'rows') = 'array'
                    THEN COALESCE((SELECT jsonb_object_agg(e->>'id', e) FROM jsonb_array_elements(n.value->'rows') e), '{}'::jsonb)
                    ELSE COALESCE(n.value->'rows', '{}'::jsonb) END) r
                WHERE cp.scrape_campaign_id = ? AND cp.name <> ?", [$campaign->id, $name]) as $row) {
                $ids[(string) $row->id] = true;
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
