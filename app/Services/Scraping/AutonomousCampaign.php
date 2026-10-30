<?php

namespace App\Services\Scraping;

use App\Models\DocumentIdentifier;
use App\Models\DocumentSource;
use App\Models\ScrapeCampaign;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunItem;
use App\Scraping\LexUz\LexHttpClient;
use App\Services\Reporting\CampaignReportingService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Deterministic orchestration. Controller and worker hand over the ORIGINAL campaign lock. */
class AutonomousCampaign
{
    private CampaignSessionMutex $mutex;

    private bool $shutdown = false;

    private ?string $id = null;

    public function __construct(private DurableCampaign $campaign, private CampaignIntegrity $integrity, private BoundedDiscovery $discovery, private ScrapeRunManager $runs)
    {
        $this->mutex = new CampaignSessionMutex(2);
    }

    public function prepare(string $id): array
    {
        $definition = config('corpus_campaigns.'.$id) ?? throw new RuntimeException('Unknown approved campaign profile.');
        foreach ($definition['scope'] as $query) {
            if (! in_array($query['section'], DiscoveryPlanner::ROOTS, true) || app(DiscoveryPlanner::class)->date($query['from']) > app(DiscoveryPlanner::class)->date($query['to'])) {
                throw new RuntimeException('Invalid fixed discovery scope.');
            }
        }
        if (ScrapeCampaign::where('campaign_id', $id)->exists()) {
            $stored = ScrapeCampaign::where('campaign_id', $id)->firstOrFail();
            if (($stored->configuration_snapshot['scope'] ?? null) != $definition['scope'] || $stored->max_http_requests !== $definition['requests']
                || $stored->max_source_selections !== $definition['sources'] || $stored->target_new_canonical !== $definition['target']
                || ($stored->configuration_snapshot['stages'] ?? null) !== $definition['stages']
                || collect($definition['limits'] ?? [])->contains(fn ($value, $key) => ($stored->configuration_snapshot[$key] ?? null) !== $value)) {
                throw new RuntimeException('Existing profile bounds differ; never overwrite.');
            }

            return $this->campaign->state($id);
        }
        try {
            return DB::transaction(function () use ($id, $definition) {
                $this->campaign->create($id, $definition['requests'], $definition['sources'], $definition['target'], [
                    'authorized_scope_successor' => $definition['predecessor'], 'campaign_type' => ($definition['extended_collection'] ?? false) ? 'corpus_collection' : 'corpus_completion',
                    'autonomous' => true, 'prepare_only' => true, 'collect_once' => true, 'stages' => $definition['stages'],
                    'scope' => $definition['scope'], 'max_campaign_seconds' => 21600,
                    ...($definition['limits'] ?? []),
                ]);
                $this->campaign->saveCheckpoint('preservation-baseline', ['v1'], ['baseline' => $this->integrity->baseline()]);
                $this->campaign->saveControl(['phase' => 'discover', 'scope_index' => 0, 'pool' => [], 'run_id' => null]);

                return $this->campaign->state($id);
            });
        } finally {
            $this->campaign->release();
        }
    }

    public function begin(string $id): void
    {
        $state = $this->campaign->state($id) ?? throw new RuntimeException('Prepare the campaign first.');
        if (! ($state['configuration_snapshot']['autonomous'] ?? false)) {
            throw new RuntimeException('Campaign has no autonomous configuration; historical campaign remains unchanged.');
        }
        $this->mutex->acquire();
        $this->id = $id;
        if ($state['status'] === 'paused' && ($state['prepared'] ?? false)) {
            $this->campaign->claim($id);
            $this->campaign->resume();
            $this->campaign->release();
        }
    }

    public function requestShutdown(): void
    {
        $this->shutdown = true;
    }

    private function claim(): bool
    {
        try {
            $this->campaign->claim($this->id);

            return true;
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'global PostgreSQL session lock')) {
                return false;
            }
            throw $e;
        }
    }

    /** One bounded deterministic decision; false means paused or terminal, never queue-empty completion. */
    public function tick(): bool
    {
        $this->mutex->acquire();
        $state = $this->campaign->state($this->id);
        if ($this->shutdown && $state['status'] === 'running') {
            $this->campaign->requestPause($this->id);
            $state = $this->campaign->state($this->id);
        }
        if (in_array($state['status'], ['completed', 'stopped', 'failed'], true)) {
            if ($this->campaign->hasLiveOwner($this->id)) {
                return true;
            }
            $this->persistFinal();

            return false;
        }
        if ($state['status'] === 'paused') {
            return false;
        }
        if (! $this->claim()) {
            return true; // Active worker owns all HTTP/import authority; controller cannot steal it.
        }
        try {
            if ($this->campaign->acknowledgeSignals()) {
                return false;
            }
            $state = $this->campaign->assertOpen();
            $control = $state['control'] ?? throw new RuntimeException('Missing durable orchestrator control.');
            $pending = ScrapeRun::where('checkpoint->campaign_id', $this->id)->where(function ($q) {
                $q->whereNotIn('status', ['completed', 'completed_with_errors', 'failed']);
            })->get();
            foreach ($pending as $run) {
                // Existing receipt fencing and retry_after prevent redispatching live leases.
                $this->runs->resume($run->id);
            }
            $unfinished = ScrapeRunItem::whereHas('run', fn ($q) => $q->where('checkpoint->campaign_id', $this->id))
                ->whereNotIn('status', ScrapeRunItem::TERMINAL)->exists();
            if (DB::table('jobs')->where('queue', 'scraping')->exists() || $unfinished) {
                $control['phase'] = 'wait';
                $this->campaign->saveControl($control);

                return true;
            }
            $baseline = $this->campaign->loadCheckpoint('preservation-baseline', ['v1'])['baseline'] ?? throw new RuntimeException('Missing preservation baseline.');
            $audit = $this->integrity->audit($baseline, $this->id);
            $this->campaign->auditedStage($audit['errors']);
            if ($state['new_canonical_documents'] > $state['stage']) {
                throw new RuntimeException('Canonical stage overshoot.');
            }
            if ($state['new_canonical_documents'] === $state['stage']) {
                $this->campaign->saveCheckpoint('stage-'.$state['stage'], ['audit-v1'], ['audit' => $audit]);
                if ($state['stage'] === $state['target']) {
                    $this->campaign->complete();

                    return true; // Next tick persists a stable terminal report after releasing authority.
                }
                $index = array_search($state['stage'], $state['stages'], true);
                $this->campaign->advance($state['stages'][$index + 1]);

                return true;
            }
            $known = array_fill_keys(DocumentIdentifier::where('source_id', DocumentSource::where('slug', 'lex')->value('id'))->pluck('external_id')->all(), true);
            $excluded = array_fill_keys([...$state['selected_ids'], '1587862', '-1587862', '1587860', '8354875', '-8345596'], true);
            $eligible = array_filter($control['pool'], fn ($row) => ! isset($known[$row['id']]) && ! isset($excluded[$row['id']]));
            if ($eligible === []) {
                $scope = $state['configuration_snapshot']['scope'];
                $index = $control['scope_index'];
                if (! isset($scope[$index])) {
                    $this->campaign->stop('bounded_discovery_scope_exhausted');

                    return true;
                }
                $control['phase'] = 'discover';
                $this->campaign->saveControl($control);
                app(LexHttpClient::class)->context('campaign-discovery-'.$index);
                $coverage = $this->discovery->collect('scope-'.$index, $scope[$index], partitionCap: 127, afterPage: function (): void {
                    if ($this->shutdown) {
                        $this->campaign->requestPause($this->id);
                    }
                }, singleDayPageCap: $state['configuration_snapshot']['single_day_page_cap'] ?? null);
                if (! $coverage['complete'] && ($state['configuration_snapshot']['discovery_gap_policy'] ?? null) !== 'record_and_continue') {
                    $this->campaign->stop('unreconciled_discovery_partition');

                    return true;
                }
                if (! $coverage['complete']) {
                    $control['coverage_gaps'][(string) $index] = [
                        'scope_index' => $index, 'query' => $scope[$index],
                        'reason' => $coverage['root']['reason'] ?? 'incomplete',
                        'listing_total' => $coverage['root']['total'] ?? null,
                        'discovered_source_ids' => count($coverage['rows']), 'checked_at' => now()->toAtomString(),
                    ];
                }
                if ($state['configuration_snapshot']['replace_consumed_pool'] ?? false) {
                    $control['pool'] = []; // The previous partition has no eligible unselected IDs left.
                }
                foreach ($coverage['rows'] as $row) {
                    $control['pool'][(string) $row['id']] = $row;
                }
                $control['scope_index']++;
                $this->campaign->saveControl($control);

                return true;
            }
            $remaining = $state['source_limit'] - $state['selected_source_count'];
            if ($remaining < 1) {
                $this->campaign->stop('bounded_source_selection_budget_exhausted');

                return true;
            }
            usort($eligible, fn ($a, $b) => strcmp(hash('sha256', $a['id']), hash('sha256', $b['id'])));
            $rows = array_slice($eligible, 0, min(30, $remaining, $state['stage'] - $state['new_canonical_documents']));
            DB::transaction(function () use ($rows, &$control) {
                $run = $this->runs->start(DocumentSource::where('slug', 'lex')->firstOrFail(), 'full', count($rows), array_map('strval', array_column($rows, 'id')));
                $checkpoint = $run->checkpoint;
                $checkpoint['sample_listing_statuses'] = array_column($rows, 'listing_status', 'id');
                $run->update(['checkpoint' => $checkpoint]);
                $control['run_id'] = $run->id;
                $control['phase'] = 'wait';
                $this->campaign->saveControl($control);
            });

            return true;
        } catch (PilotStoppedException $e) {
            if (in_array($this->campaign->state()['status'], ['pausing', 'paused'], true)) {
                $this->campaign->acknowledgeSignals();
            }

            return true;
        } catch (\Throwable $e) {
            $this->campaign->stop('orchestrator_error:'.$e::class.':'.mb_substr($e->getMessage(), 0, 180));
            throw $e;
        } finally {
            $this->campaign->release();
        }
    }

    public function persistFinal(): void
    {
        $row = ScrapeCampaign::where('campaign_id', $this->id)->firstOrFail();
        if ($this->campaign->hasLiveOwner($this->id)) {
            throw new RuntimeException('Final report waits for active worker ownership to release.');
        }
        if ($row->checkpoints()->where('name', 'final-report')->exists()) {
            return;
        }
        $report = app(CampaignReportingService::class)->campaign($this->id);
        $row->checkpoints()->firstOrCreate(['name' => 'final-report'], ['bounds' => ['report-v1'], 'state' => ['report' => $report], 'owner_generation' => $row->owner_generation]);
    }

    public function close(): void
    {
        $this->campaign->release();
        $this->mutex->release();
    }
}
