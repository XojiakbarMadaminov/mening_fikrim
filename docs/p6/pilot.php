<?php

use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentIdentifier;
use App\Models\DocumentSource;
use App\Models\ScrapeRun;
use App\Scraping\LexUz\LexHttpClient;
use App\Services\Scraping\BoundedDiscovery;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/runtime.php';
$campaign = app(DurableCampaign::class);
if (($argv[1] ?? 'status') === 'audit') {
    p6Audit('integrity_current');
    exit;
}
if (($argv[1] ?? 'status') !== 'execute') {
    echo json_encode($campaign->state(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    exit;
}
p6Guard();
$id = 'p6-corpus-1000-20261029';
$scope = require __DIR__.'/scope.php';
$bounds = ['p6-controller-v1', hash_file('sha256', __DIR__.'/scope.php')];
config(['logging.channels.scraping.driver' => 'single', 'logging.channels.scraping.path' => __DIR__.'/requests.jsonl']);
$app->instance(LexHttpClient::class, new class extends LexHttpClient
{
    protected function pause(float $seconds): void
    {
        $start = hrtime(true);
        parent::pause($seconds);
        file_put_contents(__DIR__.'/pacer_waits.jsonl', json_encode(['at' => now()->toAtomString(), 'seconds' => (hrtime(true) - $start) / 1e9])."\n", FILE_APPEND);
    }
});
$manager = app(ScrapeRunManager::class);
$source = DocumentSource::where('slug', 'lex')->firstOrFail();
$queryMs = 0.0;
$queryCount = 0;
DB::listen(function ($event) use (&$queryMs, &$queryCount): void {
    $queryMs += $event->time;
    $queryCount++;
});
function p6Worker(int $limit = 31): void
{
    global $campaign, $queryMs, $queryCount;
    $campaign->assertOpen();
    $started = hrtime(true);
    $beforeMs = $queryMs;
    $beforeCount = $queryCount;
    app('queue.worker')->shouldQuit = false;
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--tries' => 3, '--timeout' => 900,
        '--memory' => 512, '--sleep' => 1, '--stop-when-empty' => true, '--max-jobs' => $limit, '--max-time' => 3600], new ConsoleOutput);
    file_put_contents(__DIR__.'/worker_profiles.jsonl', json_encode(['at' => now()->toAtomString(), 'wall_seconds' => (hrtime(true) - $started) / 1e9,
        'peak_memory' => memory_get_peak_usage(true), 'query_ms' => $queryMs - $beforeMs, 'query_count' => $queryCount - $beforeCount,
        'self_usage' => getrusage(), 'child_usage' => getrusage(1)])."\n", FILE_APPEND);
    $campaign->assertOpen();
}
function p6Drain(): void
{
    global $campaign;
    $tries = 0;
    $waited = 0.0;
    $maximumWait = (int) config('queue.connections.database.retry_after') + 60;
    while (DB::table('jobs')->where('queue', 'scraping')->exists()) {
        $delay = $campaign->queueDelaySeconds();
        if ($waited + $delay > $maximumWait) {
            throw new RuntimeException('Queue recovery delay exceeds its fixed retry_after allowance.');
        }
        if ($delay > 0) {
            $started = hrtime(true);
            echo 'QUEUE_WAIT seconds='.min(30, $delay)."\n";
            flush();
            sleep(min(30, $delay));
            $seconds = (hrtime(true) - $started) / 1e9;
            $waited += $seconds;
            file_put_contents(__DIR__.'/queue_recovery_waits.jsonl', json_encode(['at' => now()->toAtomString(), 'seconds' => $seconds])."\n", FILE_APPEND);

            continue;
        }
        if (++$tries > 4) {
            throw new RuntimeException('Queue not drained within bounded retries.');
        }
        p6Worker();
    }
}
function p6Batch(array $rows, string $kind): void
{
    global $campaign, $manager, $source, $progress, $bounds;
    $ids = array_map('strval', array_column($rows, 'id'));
    $run = $manager->start($source, 'full', count($ids), $ids);
    $checkpoint = $run->checkpoint;
    $checkpoint['sample_listing_statuses'] = array_column($rows, 'listing_status', 'id');
    $checkpoint['pilot_kind'] = $kind;
    $run->update(['checkpoint' => $checkpoint]);
    echo 'RUN '.$run->id.' kind='.$kind.' selected='.count($ids).' new='.$campaign->newCount().' stage='.$campaign->state()['stage']."\n";
    flush();
    if (! ($progress['restart_done'] ?? false) && $kind === 'new_collection') {
        p6Worker(6);
        $receipts = DB::table('jobs')->where('queue', 'scraping')->orderBy('id')->pluck('payload', 'id')->all();
        $completed = $run->items()->whereIn('status', ['imported', 'unchanged', 'review', 'skipped_alias'])->pluck('id')->all();
        $manager->resume($run->id);
        $same = $receipts === DB::table('jobs')->where('queue', 'scraping')->orderBy('id')->pluck('payload', 'id')->all();
        p6Save('worker_resume', ['method' => 'graceful bounded worker stop after6 jobs, resume and restart', 'run_id' => $run->id,
            'completed_item_ids' => $completed, 'pending_receipt_ids' => array_keys($receipts), 'receipts_unchanged' => $same]);
        if (! $same) {
            throw new RuntimeException('Resume duplicated or changed receipts.');
        }
        $progress['restart_done'] = true;
        $campaign->saveCheckpoint('controller', $bounds, ['nodes' => [], 'progress' => $progress]);
    }
    p6Drain();
    p6RunReport($run->id);
    p6Audit('audit_run_'.$run->id);
    $run->refresh();
    if ($run->items()->where('status', 'failed')->exists()) {
        throw new RuntimeException('Technical run-item failure.');
    }
    if ($run->items()->whereNotNull('document_id')->get()->contains(fn ($item) => ($item->outcome['identity_verified'] ?? false) !== true)) {
        throw new RuntimeException('Persisted source lacks verified identity evidence.');
    }
    $campaign->auditedStage([]);
}
$started = hrtime(true);
try {
    if ($campaign->id() !== $id) {
        $prior = $campaign->state();
        if (($prior['id'] ?? null) !== 'p6-validation-20261029' || $prior['status'] !== 'completed' || $prior['used'] > 2000
            || $prior['new_canonical_documents'] !== 0) {
            throw new RuntimeException('Completed P6 validation campaign required before collection.');
        }
        $campaign->create($id, 13000, 3300, 1000, ['collect_once' => true, 'scope_sha256' => $bounds[1],
            'baseline_sha256' => hash_file('sha256', __DIR__.'/database_baseline.json'), 'archive_quota_bytes' => 2147483648]);
    } else {
        $campaign->claim($id);
    }
    $campaign->assertOpen();
    $config = $campaign->state()['configuration_snapshot'];
    if ($config['scope_sha256'] !== $bounds[1] || $config['baseline_sha256'] !== hash_file('sha256', __DIR__.'/database_baseline.json')) {
        throw new RuntimeException('Fixed scope or audit baseline changed.');
    }
    $progress = $campaign->loadCheckpoint('controller', $bounds)['progress'] ?? ['discovery_index' => 0, 'pool' => [], 'restart_done' => false, 'repeat_done' => false];
    $campaign->saveCheckpoint('controller', $bounds, ['nodes' => [], 'progress' => $progress]);
    p6Audit('pre_collection');
    // Recover only this campaign's runs. Historical P5/P5.1 records are never reconciled.
    if (DB::table('jobs')->where('queue', 'scraping')->exists()) {
        foreach (p6RunIds($id) as $runId) {
            if (! in_array(ScrapeRun::findOrFail($runId)->status, ['completed', 'completed_with_errors', 'failed'], true)) {
                $manager->resume($runId);
            }
        }
        p6Drain();
        p6Audit('recovered_queue');
    }
    // Explicit one-time retry of the two source-backed script-label defects, after regression tests.
    // Existing guarded failures stay in their original run; no budget/checkpoint reset occurs.
    if (! ($progress['script_label_recheck_done'] ?? false)) {
        $retryIds = DB::table('scrape_run_items AS item')->join('scrape_runs AS run', 'run.id', '=', 'item.scrape_run_id')
            ->where('run.checkpoint->campaign_id', $id)->whereIn('item.source_external_id', ['-4688498', '5231767'])
            ->where('item.status', 'review')->whereNull('item.document_id')
            ->whereIn('item.last_error', ['Unknown source document language: Ўзбекча (к)', 'Unknown source document language: O‘zbekcha (k)'])
            ->distinct()->orderBy('item.source_external_id')->pluck('item.source_external_id')->all();
        if ($retryIds !== [] && ! ScrapeRun::where('checkpoint->campaign_id', $id)->where('checkpoint->pilot_kind', 'script_label_recheck')->exists()) {
            if ($campaign->state()['stage'] - $campaign->newCount() < count($retryIds)) {
                throw new RuntimeException('Script-label recheck must fit the existing canonical stage cap.');
            }
            p6Batch(array_map(fn ($sourceId) => ['id' => $sourceId, 'listing_status' => 'Y'], $retryIds), 'script_label_recheck');
        }
        $progress['script_label_recheck_done'] = true;
        $campaign->saveCheckpoint('controller', $bounds, ['nodes' => [], 'progress' => $progress]);
    }
    while ($campaign->newCount() < 1000) {
        $state = $campaign->assertOpen();
        $count = $campaign->newCount();
        if ($count === $state['stage']) {
            $audit = p6Audit('stage_'.$state['stage']);
            $campaign->auditedStage($audit['errors']);
            $next = $state['stages'][array_search($state['stage'], $state['stages'], true) + 1];
            $campaign->advance($next);

            continue;
        }
        if (! $progress['repeat_done'] && $count >= 3 && $state['stage'] - $count >= 3) {
            $docs = Document::where('id', '>', $state['baseline_max_id'])->orderBy('id')->limit(3)->get();
            $before = DocumentContent::orderBy('id')->get(['id', 'document_id', 'language', 'content_hash', 'quality_status'])->toArray();
            p6Batch($docs->map(fn ($d) => ['id' => $d->external_id, 'listing_status' => 'Y'])->all(), 'repeat_control');
            $same = $before === DocumentContent::orderBy('id')->get(['id', 'document_id', 'language', 'content_hash', 'quality_status'])->toArray();
            p6Save('repeat_import', ['document_ids' => $docs->pluck('id')->all(), 'content_ids_hashes_quality_unchanged' => $same]);
            if (! $same) {
                throw new RuntimeException('Repeat import changed stored content unexpectedly.');
            }
            $progress['repeat_done'] = true;
            $campaign->saveCheckpoint('controller', $bounds, ['nodes' => [], 'progress' => $progress]);

            continue;
        }
        $known = array_fill_keys(DocumentIdentifier::where('source_id', $source->id)->pluck('external_id')->map(fn ($value) => (string) $value)->all(), true);
        $excluded = array_fill_keys([...$state['selected_ids'], '1587862', '-1587862', '1587860', '8354875', '-8345596'], true);
        $remaining = $state['source_limit'] - $state['selected_source_count'];
        if ($remaining < 1) {
            $campaign->stop('bounded_source_selection_budget_exhausted');
            break;
        }
        $limit = min(30, $state['stage'] - $count, $remaining);
        $eligible = [];
        foreach ($progress['pool'] as $row) {
            if (! isset($known[$row['id']]) && ! isset($excluded[$row['id']])) {
                $eligible[(string) $row['id']] = $row;
            }
        }
        // Complete six varied roots before the first batch; then expand only when pool is low.
        if (($progress['discovery_index'] < min(6, count($scope))) || count($eligible) < $limit) {
            if ($progress['discovery_index'] < count($scope)) {
                $index = $progress['discovery_index'];
                $query = $scope[$index];
                app(LexHttpClient::class)->context('p6-discovery-'.$index);
                $coverage = app(BoundedDiscovery::class)->collect('scope-'.$index, $query, partitionCap: 127, afterPage: function ($page): void {
                    echo 'DISCOVERY page='.$page['page'].' total='.$page['total']."\n";
                    flush();
                });
                p6Save('discovery_'.$index, $coverage);
                if (! $coverage['complete']) {
                    throw new RuntimeException('Discovery partition remains incomplete.');
                }
                foreach ($coverage['rows'] as $row) {
                    $row['sampling_root'] = $query['section'];
                    $progress['pool'][(string) $row['id']] = $row;
                }
                $progress['discovery_index']++;
                $campaign->saveCheckpoint('controller', $bounds, ['nodes' => [], 'progress' => $progress]);

                continue;
            }
            if ($eligible === []) {
                $campaign->stop('bounded_discovery_scope_exhausted');
                break;
            }
        }
        // Deterministic round-robin across roots, with numeric spacing to reduce adjacent aliases.
        $groups = [];
        foreach ($eligible as $row) {
            $groups[$row['sampling_root']][] = $row;
        }
        ksort($groups);
        foreach ($groups as &$group) {
            usort($group, fn ($a, $b) => strcmp(hash('sha256', $a['id']), hash('sha256', $b['id'])));
        }
        unset($group);
        $rows = [];
        while (count($rows) < $limit && $groups) {
            foreach ($groups as $root => &$group) {
                $rows[] = array_shift($group);
                if ($group === []) {
                    unset($groups[$root]);
                }
                if (count($rows) === $limit) {
                    break;
                }
            }
            unset($group);
        }
        p6Batch($rows, 'new_collection');
    }
    $audit = p6Audit('final_integrity');
    if ($campaign->newCount() === 1000) {
        p6Save('stage_1000', $audit);
        $campaign->auditedStage($audit['errors']);
        $campaign->complete();
    }
} catch (Throwable $error) {
    p6FinishFailure($error);
} finally {
    foreach (p6RunIds($id) as $runId) {
        p6RunReport($runId);
    }
    $execution = ['controller_wall_seconds' => (hrtime(true) - $started) / 1e9, 'finished_at' => now()->toAtomString(), 'campaign' => $campaign->state()];
    p6Save('collection_execution', $execution);
    file_put_contents(__DIR__.'/controller_invocations.jsonl', json_encode($execution)."\n", FILE_APPEND);
    $campaign->release();
}
