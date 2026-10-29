<?php

namespace App\Services\Scraping;

use App\Models\Document;
use App\Models\ScrapeRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/** Bounded pilot coordination in existing database-cache infrastructure, not a new business ledger. */
class PilotCampaign
{
    public function id(): ?string
    {
        return Cache::get('lex:pilot:active');
    }

    public function create(string $id, int $requests, int $target): array
    {
        if (! preg_match('/^[a-z0-9-]{1,60}$/D', $id) || $requests < 1 || $requests > 4000 || $target < 1 || $target > 200) {
            throw new InvalidArgumentException('Pilot requires a unique ID, 1..4000 requests and 1..200 new documents.');
        }

        return Cache::lock('lex:http:single-request', 30)->block(35, function () use ($id, $requests, $target) {
            if ($this->id() || Cache::has('lex:pilot:'.$id) || ScrapeRun::where('checkpoint->campaign_id', $id)->exists() || DB::table('jobs')->where('queue', 'scraping')->exists()) {
                throw new RuntimeException('Existing campaign or queued work: refuse to reset a budget.');
            }
            $state = ['id' => $id, 'request_limit' => $requests, 'used' => 0, 'target' => $target, 'stage' => min(30, $target),
                'baseline_max_id' => (int) Document::max('id'), 'baseline_count' => Document::count(),
                'started_at' => now()->toAtomString(), 'stopped' => null, 'consecutive_errors' => 0];
            Cache::forever('lex:pilot:'.$id, $state);
            Cache::forever('lex:pilot:active', $id);

            return $state;
        });
    }

    public function state(): ?array
    {
        if (! $id = $this->id()) {
            return null;
        }

        return Cache::get('lex:pilot:'.$id) ?? throw new PilotStoppedException('campaign state missing; no automatic recreation');
    }

    /** Explicit separately authorized follow-up; never edits or reopens the preceding state. */
    public function followup(string $previousId, string $id, int $requests, int $sources): array
    {
        if (! preg_match('/^[a-z0-9-]{1,60}$/D', $id) || $requests < 1 || $requests > 4000 || $sources < 42 || $sources > 250 || $id === $previousId) {
            throw new InvalidArgumentException('Follow-up requires a new ID, 1..4000 HTTP requests and 42..250 selected source IDs.');
        }

        return Cache::lock('lex:http:single-request', 30)->block(35, function () use ($previousId, $id, $requests, $sources) {
            $previous = $this->state();
            if (($previous['id'] ?? null) !== $previousId || ($previous['stopped']['reason'] ?? null) !== 'bounded_source_selection_budget_exhausted'
                || Cache::has('lex:pilot:'.$id) || ScrapeRun::where('checkpoint->campaign_id', $id)->exists() || DB::table('jobs')->where('queue', 'scraping')->exists()) {
                throw new RuntimeException('Follow-up requires the named source-cap-stopped predecessor, new identity and an empty queue.');
            }
            $state = ['id' => $id, 'request_limit' => $requests, 'used' => 0, 'target' => 42, 'stage' => 20,
                'stages' => [20, 42], 'source_limit' => $sources, 'selected_ids' => [], 'audit_count' => null,
                'previous_id' => $previousId, 'previous_state_sha256' => hash('sha256', json_encode($previous)),
                'baseline_max_id' => (int) Document::max('id'), 'baseline_count' => Document::count(),
                'started_at' => now()->toAtomString(), 'stopped' => null, 'consecutive_errors' => 0];
            Cache::forever('lex:pilot:'.$id, $state);
            Cache::forever('lex:pilot:active', $id);

            return $state;
        });
    }

    public function selectSources(array $ids): void
    {
        Cache::lock('lex:http:single-request', 30)->block(35, function () use ($ids) {
            $state = $this->assertOpen();
            if (! isset($state['source_limit'])) {
                return;
            }
            foreach ($ids as $id) {
                if (! is_string($id) || ! preg_match('/^-?\d+$/D', $id)) {
                    throw new InvalidArgumentException('Source selection requires signed string IDs.');
                }
            }
            $selected = array_values(array_unique([...$state['selected_ids'], ...$ids]));
            if (count($selected) > $state['source_limit']) {
                $this->stop('bounded_source_selection_budget_exhausted');
                throw new PilotStoppedException('bounded_source_selection_budget_exhausted');
            }
            $state['selected_ids'] = $selected;
            Cache::forever('lex:pilot:'.$state['id'], $state);
        });
    }

    /** Controller supplies the result of its full baseline-preservation audit; fail closed. */
    public function auditedStage(array $errors): void
    {
        $state = $this->assertOpen() ?? throw new RuntimeException('No campaign.');
        if ($errors || DB::table('jobs')->where('queue', 'scraping')->exists()) {
            $this->stop('stage_integrity_audit_failed');
            throw new PilotStoppedException('stage_integrity_audit_failed');
        }
        $state['audit_count'] = $this->newCount($state);
        Cache::forever('lex:pilot:'.$state['id'], $state);
    }

    public function assertOpen(): ?array
    {
        $state = $this->state();
        if ($state && $state['stopped']) {
            throw new PilotStoppedException($state['stopped']['reason']);
        }

        return $state;
    }

    public function newCount(?array $state = null): int
    {
        $state ??= $this->state();

        return $state ? Document::where('id', '>', $state['baseline_max_id'])->whereHas('source', fn ($q) => $q->where('slug', 'lex'))->count() : 0;
    }

    /** Called under the shared HTTP lock, including redirects, retries and discovery. */
    public function reserve(): ?string
    {
        $state = $this->assertOpen();
        if (! $state) {
            return null;
        }
        if ($state['used'] >= $state['request_limit']) {
            $this->stop('campaign_request_budget_exhausted');
            throw new PilotStoppedException('campaign_request_budget_exhausted');
        }
        $state['used']++;
        Cache::forever('lex:pilot:'.$state['id'], $state);

        return $state['id'];
    }

    /** Called under the SAME HTTP lock; two consecutive 5xx/network errors latch a stop. */
    public function observe(int $status): void
    {
        if (! $state = $this->state()) {
            return;
        }
        $state['consecutive_errors'] = $status === 0 || $status >= 500 ? $state['consecutive_errors'] + 1 : 0;
        Cache::forever('lex:pilot:'.$state['id'], $state);
        if (in_array($status, [403, 429], true) || $state['consecutive_errors'] >= 2) {
            $this->stop('source_http_'.$status.($state['consecutive_errors'] >= 2 ? '_repeated' : ''));
        }
    }

    /** Caller holds HTTP lock, or is the single pilot controller while the worker is idle. */
    public function stop(string $reason): void
    {
        if ($state = $this->state()) {
            $state['stopped'] ??= ['reason' => $reason, 'at' => now()->toAtomString()];
            Cache::forever('lex:pilot:'.$state['id'], $state);
        }
    }

    public function admitRun(int $limit, array $sample = []): ?string
    {
        $state = $this->assertOpen();
        if (! $state) {
            return null;
        }
        if (DB::table('jobs')->where('queue', 'scraping')->exists() || $limit > min(30, $state['stage'] - $this->newCount($state))) {
            throw new RuntimeException('Pilot stage allowance exceeded or unfinished queue exists.');
        }
        if (isset($state['source_limit'])) {
            if (count($sample) !== $limit) {
                throw new RuntimeException('Follow-up collection requires an explicit bounded selection from checkpointed discovery.');
            }
            $this->selectSources($sample);
        }

        return $state['id'];
    }

    public function advance(int $stage): array
    {
        $state = $this->assertOpen() ?? throw new RuntimeException('No campaign.');
        $next = isset($state['stages']) ? ($state['stages'][array_search($state['stage'], $state['stages'], true) + 1] ?? $state['target'])
            : ($state['stage'] === 30 ? min(100, $state['target']) : $state['target']);
        if (isset($state['stages']) && ($state['audit_count'] ?? null) !== $state['stage']) {
            throw new RuntimeException('Stage requires a successful current integrity audit.');
        }
        if ($stage !== $next || $stage <= $state['stage'] || $this->newCount($state) !== $state['stage'] || DB::table('jobs')->where('queue', 'scraping')->exists()) {
            throw new RuntimeException('Stage gate requires exact preceding new-canonical target and an empty queue.');
        }
        $state['stage'] = $stage;
        Cache::forever('lex:pilot:'.$state['id'], $state);

        return $state;
    }

    public function allowsRun(ScrapeRun $run): bool
    {
        $id = $run->checkpoint['campaign_id'] ?? null;
        // A campaign receipt cannot escape its budget if configuration/cache is lost.
        if ($id !== null && $id !== $this->id()) {
            return false;
        }

        return ! ($this->state()['stopped'] ?? false);
    }
}
