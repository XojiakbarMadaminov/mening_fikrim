<?php

/** Explicit P5.1 controller. Every source request uses the production shared HTTP client. */
use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentIdentifier;
use App\Models\DocumentSource;
use App\Models\ScrapeRun;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzScraper;
use App\Scraping\LexUz\SourceReviewException;
use App\Services\Scraping\BoundedDiscovery;
use App\Services\Scraping\PilotCampaign;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development database required.');
}
function save(string $name, mixed $value): void
{
    file_put_contents(__DIR__.'/'.$name.'.json', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", LOCK_EX);
}
function load(string $name, mixed $default = null): mixed
{
    return is_file(__DIR__.'/'.$name.'.json') ? json_decode(file_get_contents(__DIR__.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR) : $default;
}
function snapshot(string $name): array
{
    $result = ['at' => now()->toAtomString(), 'rows' => [], 'contents' => [], 'documents' => [], 'identifiers' => [], 'failed_hashes' => []];
    foreach (['documents', 'document_identifiers', 'document_contents', 'scrape_runs', 'scrape_run_items', 'failed_jobs', 'jobs', 'migrations'] as $table) {
        $result['rows'][$table] = DB::table($table)->count();
    }
    foreach (DocumentContent::orderBy('id')->cursor() as $c) {
        $result['contents'][$c->id] = ['document_id' => $c->document_id, 'language' => $c->language, 'hash' => $c->content_hash,
            'hash_matches' => hash('sha256', $c->content) === $c->content_hash, 'characters' => mb_strlen($c->content), 'quality_status' => $c->quality_status,
            'quality_issues' => $c->quality_issues, 'source_url' => $c->extraction_metadata['source_url'] ?? null,
            'format' => $c->extraction_metadata['quality']['format'] ?? null, 'pending_candidate_hash' => $c->pending_candidate['content_hash'] ?? null];
    }
    $result['documents'] = Document::orderBy('id')->get(['id', 'source_id', 'external_id', 'status'])->toArray();
    $result['identifiers'] = DocumentIdentifier::orderBy('id')->get(['id', 'source_id', 'document_id', 'external_id', 'language'])->toArray();
    foreach (DB::table('failed_jobs')->orderBy('id')->get() as $row) {
        $data = (array) $row;
        ksort($data);
        $result['failed_hashes'][$row->id] = hash('sha256', json_encode($data));
    }
    $result['quality_counts'] = array_count_values(array_column($result['contents'], 'quality_status'));
    $baseline = load('database_baseline');
    $errors = [];
    foreach ($baseline['contents'] as $id => $old) {
        $new = $result['contents'][$id] ?? [];
        foreach (['document_id', 'language', 'hash', 'quality_status'] as $key) {
            if (($new[$key] ?? null) !== $old[$key]) {
                $errors[] = 'original_content_changed:'.$id.':'.$key;
            }
        }
    }
    foreach ($result['contents'] as $id => $c) {
        if (! $c['hash_matches'] || $c['characters'] < 1 || ($c['quality_status'] === 'verified' && $c['quality_issues'] !== [])) {
            $errors[] = 'invalid_content:'.$id;
        }
    }
    $newDocs = array_column($result['documents'], null, 'id');
    foreach ($baseline['documents'] as $old) {
        foreach (['source_id', 'external_id'] as $key) {
            if (($newDocs[$old['id']][$key] ?? null) !== $old[$key]) {
                $errors[] = 'canonical_changed:'.$old['id'];
            }
        }
    }
    $newIds = array_column($result['identifiers'], null, 'id');
    foreach ($baseline['identifiers'] as $old) {
        foreach (['source_id', 'external_id', 'document_id'] as $key) {
            if (($newIds[$old['id']][$key] ?? null) !== $old[$key]) {
                $errors[] = 'identifier_changed:'.$old['id'];
            }
        }
    }
    foreach ($baseline['record_hashes']['failed_jobs'] as $id => $hash) {
        if (($result['failed_hashes'][$id] ?? null) !== $hash) {
            $errors[] = 'historical_failed_job_changed:'.$id;
        }
    }
    $sql = [
        'duplicate_languages' => 'select count(*) as n from (select document_id,language from document_contents group by document_id,language having count(*)>1) x',
        'duplicate_aliases' => 'select count(*) as n from (select source_id,external_id from document_identifiers group by source_id,external_id having count(*)>1) x',
        'orphan_contents' => 'select count(*) as n from document_contents c left join documents d on d.id=c.document_id where d.id is null',
        'orphan_identifiers' => 'select count(*) as n from document_identifiers i left join documents d on d.id=i.document_id where d.id is null or d.source_id<>i.source_id',
    ];
    foreach ($sql as $key => $query) {
        $result['checks'][$key] = (int) DB::selectOne($query)->n;
        if ($result['checks'][$key]) {
            $errors[] = $key;
        }
    }
    foreach (['scrape_runs', 'scrape_run_items'] as $table) {
        foreach (DB::table($table)->where('id', '<=', max(array_keys($baseline['record_hashes'][$table])))->orderBy('id')->get() as $row) {
            $fields = (array) $row;
            ksort($fields);
            if (($baseline['record_hashes'][$table][$row->id] ?? null) !== hash('sha256', json_encode($fields))) {
                $errors[] = 'historical_ledger_changed:'.$table.':'.$row->id;
            }
        }
    }
    $prior = load('stage_a_preflight');
    $cached = Cache::get('lex:pilot:'.$prior['campaign']['id']);
    $result['previous_campaign_unchanged'] = hash('sha256', json_encode($cached)) === $prior['campaign_hash'];
    if (! $result['previous_campaign_unchanged']) {
        $errors[] = 'previous_campaign_changed';
    }
    $result['errors'] = array_values(array_unique($errors));
    $result['campaign'] = app(PilotCampaign::class)->state();
    $result['new_canonical_documents'] = app(PilotCampaign::class)->newCount();
    save($name, $result);
    if ($errors) {
        app(PilotCampaign::class)->stop('database_integrity_violation:'.implode(',', $errors));
        throw new RuntimeException('Integrity gate stopped pilot: '.implode(',', $errors));
    }
    echo $name.' documents='.$result['rows']['documents'].' contents='.$result['rows']['document_contents'].' new='.$result['new_canonical_documents']." integrity=PASS\n";
    flush();

    return $result;
}
function runSnapshot(int $id): void
{
    $manager = app(ScrapeRunManager::class);
    $manager->reconcile($id);
    $run = ScrapeRun::findOrFail($id);
    save('run_'.$id, ['run' => $run->toArray(), 'summary' => $manager->summary($run), 'items' => $run->items()->orderBy('id')->get()->toArray()]);
}
$action = $argv[1] ?? 'status';
$pilot = app(PilotCampaign::class);
if ($action === 'audit') {
    snapshot($argv[2] ?? 'integrity_audit');
    exit;
}
if ($action === 'status') {
    echo json_encode(['state' => $pilot->state(), 'new_canonical' => $pilot->newCount()], JSON_PRETTY_PRINT)."\n";
    exit;
}
if ($action !== 'execute' || ! config('scraping.live_enabled') || config('scraping.scheduler_enabled') || config('scraping.workers') !== 1 || config('scraping.request_delay_ms') < 2000 || config('scraping.request_budget') !== 400 || config('cache.default') !== 'database') {
    throw new RuntimeException('Explicit execute, fixed dev DB, database cache, live flag, one worker, >=2000ms, per-run400, disabled scheduling required.');
}
$pilot->assertOpen() ?? throw new RuntimeException('Create finite campaign explicitly first.');
config(['logging.channels.scraping.driver' => 'single', 'logging.channels.scraping.path' => __DIR__.'/requests.jsonl']);
$app->instance(LexHttpClient::class, new class extends LexHttpClient
{
    protected function pause(float $seconds): void
    {
        $started = hrtime(true);
        parent::pause($seconds);
        file_put_contents(__DIR__.'/pacer_waits.jsonl', json_encode(['at' => now()->toAtomString(), 'requested_seconds' => $seconds, 'actual_seconds' => (hrtime(true) - $started) / 1e9])."\n", FILE_APPEND);
    }
});
$source = DocumentSource::where('slug', 'lex')->firstOrFail();
$manager = app(ScrapeRunManager::class);
$scraper = app(LexUzScraper::class);
$progress = load('progress', ['runs' => [], 'attempted_ids' => [], 'repeat_done' => false, 'restart_done' => false, 'finished_stages' => []]);
$profiles = load('worker_profiles', []);
$dbQueryMs = 0.0;
$dbQueries = 0;
DB::listen(function ($event) use (&$dbQueryMs, &$dbQueries): void {
    $dbQueryMs += $event->time;
    $dbQueries++;
});
function worker(int $maxJobs = 31): void
{
    global $profiles, $dbQueryMs, $dbQueries, $pilot;
    $pilot->assertOpen();
    $started = hrtime(true);
    $beforeMs = $dbQueryMs;
    $beforeQueries = $dbQueries;
    // Fresh Worker state for each bounded invocation in this single controller process.
    app('queue.worker')->shouldQuit = false;
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--tries' => 3, '--timeout' => 900,
        '--memory' => 512, '--sleep' => 1, '--stop-when-empty' => true, '--max-jobs' => $maxJobs, '--max-time' => 3600], new ConsoleOutput);
    $profiles[] = ['at' => now()->toAtomString(), 'wall_seconds' => (hrtime(true) - $started) / 1e9, 'php_peak_bytes' => memory_get_peak_usage(true),
        'self_usage' => getrusage(), 'child_usage' => getrusage(1), 'database_query_ms' => $dbQueryMs - $beforeMs, 'database_queries' => $dbQueries - $beforeQueries];
    save('worker_profiles', $profiles);
    $pilot->assertOpen();
}
function drain(): void
{
    for ($i = 0; $i < 4 && DB::table('jobs')->where('queue', 'scraping')->exists(); $i++) {
        $next = DB::table('jobs')->where('queue', 'scraping')->min('available_at');
        $wait = max(0, $next - time());
        if ($wait > 60) {
            app(PilotCampaign::class)->stop('queue_delay_requires_investigation');
            throw new RuntimeException('Delayed queue retained; no automatic bypass.');
        }
        if ($wait) {
            sleep($wait);
        }
        worker();
    }
    if (DB::table('jobs')->where('queue', 'scraping')->exists()) {
        app(PilotCampaign::class)->stop('queue_not_drained_within_bound');
        throw new RuntimeException('Queue retry safety stop.');
    }
}
function batch(array $rows, string $kind): int
{
    global $manager, $source, $progress, $pilot;
    $pilot->assertOpen();
    $ids = array_map('strval', array_column($rows, 'id'));
    if (count($ids) < 1 || count($ids) > 30 || count(array_unique($ids)) !== count($ids)) {
        throw new RuntimeException('Bounded unique sample required.');
    }
    $run = $manager->start($source, 'full', count($ids), $ids);
    $cp = $run->checkpoint;
    $cp['sample_listing_statuses'] = array_column($rows, 'listing_status', 'id');
    $cp['pilot_kind'] = $kind;
    $run->update(['checkpoint' => $cp]);
    $progress['runs'][] = $run->id;
    $progress['attempted_ids'] = array_values(array_unique([...$progress['attempted_ids'], ...$ids]));
    save('progress', $progress);
    echo 'RUN '.$run->id.' kind='.$kind.' source_ids='.count($ids).' stage='.$pilot->state()['stage']."\n";
    flush();
    if (! $progress['restart_done'] && $kind === 'new_collection') {
        worker(6);
        $receiptsBefore = DB::table('jobs')->orderBy('id')->pluck('payload', 'id')->all();
        $completedBefore = $run->items()->whereIn('status', ['imported', 'unchanged', 'review', 'skipped_alias'])->pluck('id')->all();
        $manager->resume($run->id);
        $receiptsAfter = DB::table('jobs')->orderBy('id')->pluck('payload', 'id')->all();
        save('interruption_resume', ['method' => 'graceful bounded worker stop after6 jobs; explicit resume; restart same single worker',
            'run_id' => $run->id, 'completed_item_ids' => $completedBefore, 'remaining_receipt_ids' => array_keys($receiptsBefore), 'receipts_unchanged_on_resume' => $receiptsBefore === $receiptsAfter]);
        if ($receiptsBefore !== $receiptsAfter) {
            $pilot->stop('resume_duplicated_queue_receipts');
            throw new RuntimeException('Resume receipt mismatch.');
        }
        $progress['restart_done'] = true;
        save('progress', $progress);
    }
    drain();
    runSnapshot($run->id);
    $audit = snapshot('after_run_'.$run->id);
    if ($run->items()->where('status', 'failed')->exists() || $audit['rows']['failed_jobs'] !== load('database_baseline')['rows']['failed_jobs']) {
        $pilot->stop('technical_failures_require_investigation');
        throw new RuntimeException('Technical failure gate.');
    }

    return $run->id;
}
$started = hrtime(true);
try {
    if ($pilot->state()['id'] !== 'p5-1-20261029' || $pilot->state()['source_limit'] !== 180 || $pilot->state()['request_limit'] !== 1800) {
        throw new RuntimeException('P5.1 predeclared campaign required.');
    }
    if (DB::table('jobs')->where('queue', 'scraping')->exists()) {
        foreach ($progress['runs'] as $id) {
            $manager->resume($id);
        }
        drain();
    }
    if (! load('language_controls')) {
        $rows = array_map(fn ($id) => ['id' => $id, 'listing_status' => 'Y'], ['1597458', '8119972', '5210585', '8345596', '4797522']);
        $controlRun = batch($rows, 'language_pdf_ocr_controls');
        save('language_controls', ['run_id' => $controlRun, 'ids' => array_column($rows, 'id')]);
    }
    // Diagnostic-only fetches: never pass contradictory identity to the importer.
    $diagnostics = load('identity_diagnostics', []);
    foreach (['1587862', '-1587862', '1587860', '8354875', '-8345596'] as $id) {
        if (isset($diagnostics[$id])) {
            continue;
        }
        $pilot->selectSources([$id]);
        app(LexHttpClient::class)->context('p51-identity-'.$id);
        $before = $pilot->state()['used'];
        try {
            $candidate = $scraper->fetch($id, 'Y');
            $diagnostics[$id] = ['identity_valid' => true, 'identity' => $candidate['identity'], 'persisted' => false, 'reason' => 'diagnostic only; no speculative merge'];
        } catch (SourceReviewException|ValidationException $e) {
            $diagnostics[$id] = ['identity_valid' => false, 'persisted' => false, 'error' => $e instanceof ValidationException ? $e->errors() : $e->getMessage()];
        }
        $diagnostics[$id]['requests'] = $pilot->state()['used'] - $before;
        save('identity_diagnostics', $diagnostics);
        echo 'IDENTITY '.$id.' '.json_encode($diagnostics[$id], JSON_UNESCAPED_UNICODE)."\n";
        flush();
    }
    $query = ['section' => 'oliy', 'status' => 'Y', 'from' => '01.01.2020', 'to' => '31.12.2020'];
    $discovery = app(BoundedDiscovery::class);
    $before = $pilot->state()['used'];
    app(LexHttpClient::class)->context('p51-discovery-oliy-2020');
    $coverage = $discovery->collect('oliy-2020', $query, afterPage: function ($page) {
        echo 'DISCOVERY page='.$page['page'].' total='.$page['total'].' rows='.count($page['rows'])."\n";
        flush();
    });
    save('discovery_coverage', $coverage);
    echo 'COVERAGE complete='.json_encode($coverage['complete']).' unique='.count($coverage['rows']).' partitions='.count($coverage['nodes'])."\n";
    // Repeating completed coverage must produce zero additional requests.
    $cachedBefore = $pilot->state()['used'];
    $repeatCoverage = $discovery->collect('oliy-2020', $query);
    save('discovery_cached_repeat', ['requests' => $pilot->state()['used'] - $cachedBefore, 'identical_rows' => $repeatCoverage['rows'] === $coverage['rows']]);
    // Independent fresh enumeration of one complete leaf checks source stability.
    if (! load('discovery_fresh_replay')) {
        foreach ($coverage['nodes'] as $node) {
            if ($node['complete'] && ! isset($node['children'])) {
                $rows = [];
                app(LexHttpClient::class)->context('p51-discovery-replay');
                $replay = $scraper->discover($node['query'], function ($page) use (&$rows) {
                    foreach ($page['rows'] as $row) {
                        $rows[(string) $row['id']] = $row;
                    }

                    return true;
                }, 8);
                $stable = array_keys($rows) === array_keys($node['rows']) && $replay['complete'] && $replay['total'] === $node['total'];
                save('discovery_fresh_replay', ['query' => $node['query'], 'result' => $replay, 'stable_order_and_total' => $stable]);
                if (! $stable) {
                    throw new RuntimeException('Discovery fresh replay drift.');
                }
                break;
            }
        }
    }
    $stageB = snapshot('stage_b_results');
    $pilot->auditedStage($stageB['errors']);
    if (! $coverage['complete']) {
        throw new RuntimeException('Bounded discovery remains incomplete; no silent coverage claim.');
    }
    $pool = $coverage['rows'];
    save('sampling_pool', $pool);
    while ($pilot->newCount() < 42) {
        $pilot->assertOpen();
        $state = $pilot->state();
        $count = $pilot->newCount();
        if ($count === $state['stage']) {
            $audit = snapshot('stage_c_results');
            $pilot->auditedStage($audit['errors']);
            $progress['finished_stages'][] = $state['stage'];
            save('progress', $progress);
            $pilot->advance(42);

            continue;
        }
        if (! $progress['repeat_done'] && $count >= 3 && $state['stage'] - $count >= 3) {
            $docs = Document::where('id', '>', $state['baseline_max_id'])->orderBy('id')->limit(3)->get();
            $beforeRepeat = snapshot('repeat_before');
            batch($docs->map(fn ($d) => ['id' => $d->external_id, 'listing_status' => 'Y'])->all(), 'repeat_control');
            $afterRepeat = snapshot('repeat_after');
            $stable = $beforeRepeat['rows']['documents'] === $afterRepeat['rows']['documents']
                && $beforeRepeat['rows']['document_contents'] === $afterRepeat['rows']['document_contents']
                && array_column($beforeRepeat['contents'], 'hash') === array_column($afterRepeat['contents'], 'hash');
            save('repeat_import', ['document_ids' => $docs->pluck('id')->all(), 'counts_and_hashes_stable' => $stable]);
            if (! $stable) {
                throw new RuntimeException('Repeat-import integrity violation.');
            }
            $progress['repeat_done'] = true;
            save('progress', $progress);

            continue;
        }
        $known = DocumentIdentifier::where('source_id', $source->id)->pluck('external_id')->all();
        $remainingSelections = 180 - count($state['selected_ids']);
        if ($remainingSelections === 0) {
            $pilot->stop('bounded_source_selection_budget_exhausted');
            break;
        }
        $limit = min(20, $state['stage'] - $count, $remainingSelections);
        $rows = [];
        foreach ($pool as $row) {
            if (! in_array((string) $row['id'], $known, true) && ! in_array((string) $row['id'], $state['selected_ids'], true)) {
                $rows[] = $row;
                if (count($rows) === $limit) {
                    break;
                }
            }
        }
        if ($rows === []) {
            $pilot->stop('bounded_discovery_scope_exhausted');
            break;
        }
        batch($rows, 'new_collection');
    }
    $audit = snapshot('stage_d_results');
    if ($pilot->newCount() === 42) {
        $pilot->auditedStage($audit['errors']);
        $pilot->stop('approved_target_reached');
    }
} catch (Throwable $e) {
    if (! ($pilot->state()['stopped'] ?? false)) {
        $pilot->stop('controller_exception:'.$e::class.':'.mb_substr($e->getMessage(), 0, 240));
    }
    save('stop_diagnostic', ['at' => now()->toAtomString(), 'class' => $e::class, 'message' => $e->getMessage(), 'new_canonical_documents' => $pilot->newCount(), 'campaign' => $pilot->state()]);
    echo 'STOP '.$e->getMessage()."\n";
} finally {
    foreach ($progress['runs'] as $id) {
        runSnapshot($id);
    }
    snapshot('integrity_audit');
    save('execution', ['controller_wall_seconds' => (hrtime(true) - $started) / 1e9, 'finished_at' => now()->toAtomString(), 'campaign' => $pilot->state(), 'new_canonical_documents' => $pilot->newCount()]);
}
