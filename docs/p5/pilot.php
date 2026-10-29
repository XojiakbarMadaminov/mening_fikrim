<?php

/** Explicit P5 controller. Every source request uses the production shared HTTP client. */
use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentIdentifier;
use App\Models\DocumentSource;
use App\Models\ScrapeRun;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzScraper;
use App\Services\Scraping\PilotCampaign;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
    if (! $progress['restart_done']) {
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
    // Recover only existing receipts in a still-open campaign after controller interruption.
    if (DB::table('jobs')->where('queue', 'scraping')->exists()) {
        foreach ($progress['runs'] as $id) {
            $manager->resume($id);
        }
        drain();
    }
    $partitions = [
        ['section' => 'nat', 'status' => 'Y', 'from' => '01.01.2010', 'to' => '31.01.2010'],
        ['section' => 'loc', 'status' => 'Y', 'from' => '01.01.2020', 'to' => '31.01.2020'],
        ['section' => 'int', 'status' => 'Y', 'from' => '01.01.2020', 'to' => '31.12.2020'],
        ['section' => 'tech', 'status' => 'Y', 'from' => '01.01.2026', 'to' => '28.10.2026'],
        ['section' => 'court', 'status' => 'Y', 'from' => '01.01.2020', 'to' => '31.12.2020'],
        ['section' => 'oliy', 'status' => 'Y', 'from' => '01.01.2020', 'to' => '31.12.2020'],
    ];
    $coverage = load('discovery_coverage', []);
    foreach ($partitions as $index => $query) {
        for ($pass = 1; $pass <= 2; $pass++) {
            $key = $index.'_'.$pass;
            if (isset($coverage[$key])) {
                continue;
            }
            $pages = [];
            $requestBefore = $pilot->state()['used'];
            $at = hrtime(true);
            $result = $scraper->discover($query, function ($page) use (&$pages) {
                $pages[] = $page;

                return true;
            }, 8);
            $ids = array_map('strval', array_values(array_unique(array_merge(...array_map(fn ($p) => array_column($p['rows'], 'id'), $pages)))));
            $coverage[$key] = ['query' => $query, 'pass' => $pass, 'result' => $result, 'pages' => $pages, 'unique_ids' => $ids,
                'requests' => $pilot->state()['used'] - $requestBefore, 'duration_seconds' => (hrtime(true) - $at) / 1e9];
            if ($pass === 2) {
                $coverage[$key]['stable_order_and_total'] = $ids === $coverage[$index.'_1']['unique_ids'] && $result['total'] === $coverage[$index.'_1']['result']['total'];
                if (! $coverage[$key]['stable_order_and_total']) {
                    $pilot->stop('discovery_partition_unstable');
                }
            }
            save('discovery_coverage', $coverage);
            echo 'DISCOVERY '.$query['section'].' pass='.$pass.' total='.$result['total'].' unique='.count($ids).' complete='.json_encode($result['complete'])."\n";
            flush();
            $pilot->assertOpen();
        }
    }
    if (! load('discovery_resume')) {
        $partialPages = [];
        $partial = $scraper->discover($partitions[0], function ($page) use (&$partialPages) {
            $partialPages[] = $page;

            return false;
        }, 1);
        save('discovery_interrupted_prefix', ['query' => $partitions[0], 'result' => $partial, 'pages' => $partialPages]);
        $resumedPages = [];
        $replayed = 0;
        $resumed = $scraper->discover($partitions[0], function ($page) use (&$resumedPages, &$replayed, $partialPages) {
            if ($page['page'] === 1) {
                if ($page['prefix_fingerprint'] !== $partialPages[0]['prefix_fingerprint']) {
                    throw new RuntimeException('Interrupted discovery prefix changed.');
                }
                $replayed = count($page['rows']);
            }
            $resumedPages[] = $page;

            return true;
        }, 8);
        $resumedIds = array_map('strval', array_values(array_unique(array_merge(...array_map(fn ($page) => array_column($page['rows'], 'id'), $resumedPages)))));
        $stable = $resumedIds === $coverage['0_1']['unique_ids'] && $resumed['complete'];
        save('discovery_resume', ['query' => $partitions[0], 'interrupted_result' => $partial, 'fresh_replay_result' => $resumed, 'duplicate_prefix_ids' => $replayed, 'stable_against_complete_partition' => $stable,
            'scope' => 'real shared scraper fresh-session replay after bounded stop; no imports dispatched by this coverage probe']);
        if (! $stable) {
            $pilot->stop('discovery_resume_mismatch');
            throw new RuntimeException('Discovery resume gate failed.');
        }
    }
    $pools = [];
    foreach (array_keys($partitions) as $i) {
        $pools[] = array_merge(...array_column($coverage[$i.'_1']['pages'], 'rows'));
    }
    $pool = [];
    for ($i = 0; $i < 160; $i++) {
        foreach ($pools as $rows) {
            if (isset($rows[$i])) {
                $pool[(string) $rows[$i]['id']] = $rows[$i];
            }
        }
    }
    save('sampling_pool', array_values($pool));
    while ($pilot->newCount() < $pilot->state()['target']) {
        $pilot->assertOpen();
        $state = $pilot->state();
        if ($pilot->newCount() === $state['stage']) {
            snapshot('stage_'.($state['stage'] === 30 ? 'b' : 'c').'_results');
            $progress['finished_stages'][] = $state['stage'];
            save('progress', $progress);
            $pilot->advance(min($state['stage'] === 30 ? 100 : 200, $state['target']));

            continue;
        }
        $remaining = $state['stage'] - $pilot->newCount();
        if (! $progress['repeat_done'] && $pilot->newCount() > 0) {
            $newDocs = Document::where('id', '>', $state['baseline_max_id'])->orderBy('id')->limit(3)->get();
            $rows = $newDocs->map(fn ($d) => ['id' => $d->external_id, 'listing_status' => 'Y'])->all();
            $before = snapshot('repeat_before');
            batch($rows, 'repeat_control');
            $after = snapshot('repeat_after');
            save('repeat_import', ['document_count_stable' => $before['rows']['documents'] === $after['rows']['documents'],
                'content_count_stable' => $before['rows']['document_contents'] === $after['rows']['document_contents'],
                'all_text_hashes_stable' => array_map(fn ($c) => [$c['document_id'], $c['language'], $c['hash'], $c['quality_status']], $before['contents']) === array_map(fn ($c) => [$c['document_id'], $c['language'], $c['hash'], $c['quality_status']], $after['contents']), 'document_ids' => $newDocs->pluck('id')->all()]);
            if ($before['rows']['documents'] !== $after['rows']['documents'] || $before['rows']['document_contents'] !== $after['rows']['document_contents'] || array_column($before['contents'], 'hash') !== array_column($after['contents'], 'hash')) {
                $pilot->stop('repeat_import_changed_content');
                throw new RuntimeException('Repeat integrity gate failed.');
            }
            $progress['repeat_done'] = true;
            save('progress', $progress);

            continue;
        }
        if (count($progress['attempted_ids']) >= 360) {
            $pilot->stop('bounded_source_selection_budget_exhausted');
            break;
        }
        $known = DocumentIdentifier::where('source_id', $source->id)->pluck('external_id')->all();
        $rows = [];
        $batchLimit = min($remaining, $progress['runs'] === [] ? 20 : 30, 360 - count($progress['attempted_ids']));
        foreach ($pool as $id => $row) {
            if (! in_array((string) $id, $known, true) && ! in_array((string) $id, $progress['attempted_ids'], true)) {
                $rows[] = $row;
                if (count($rows) >= $batchLimit) {
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
    snapshot('stage_d_results');
    if ($pilot->newCount() === $pilot->state()['target']) {
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
