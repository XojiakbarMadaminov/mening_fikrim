<?php

/** Shared single-process P6 controller support. Source traffic only through LexHttpClient. */
use App\Models\DocumentContent;
use App\Models\ScrapeRun;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\ScrapeRunManager;
use App\Services\Scraping\SourceArchive;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT current_database() AS name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('P6 requires the fixed development database.');
}
function p6Save(string $name, mixed $value): void
{
    if (! preg_match('/^[a-z0-9_-]+$/D', $name)) {
        throw new RuntimeException('Invalid evidence filename.');
    }
    $path = __DIR__.'/'.$name.'.json';
    $temporary = $path.'.part';
    file_put_contents($temporary, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n", LOCK_EX);
    rename($temporary, $path);
}
function p6Read(string $name): mixed
{
    return json_decode(file_get_contents(__DIR__.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}
function p6Guard(): void
{
    if (! config('scraping.live_enabled') || config('scraping.scheduler_enabled') || config('scraping.workers') !== 1
        || config('scraping.request_delay_ms') < 2000 || config('scraping.request_budget') !== 400
        || config('cache.default') !== 'database' || config('queue.default') !== 'database') {
        throw new RuntimeException('Explicit live flag, disabled scheduling, one worker, >=2000ms, per-run400, database queue/cache required.');
    }
}
function p6History(): array
{
    $keys = ['lex:pilot:active', 'lex:pilot:p5-20261028', 'lex:pilot:p5-1-20261029'];
    $result = [];
    foreach ($keys as $key) {
        $result[$key] = hash('sha256', json_encode(Cache::get($key)));
    }

    return $result;
}
/** Read-only preservation checks. Historical content text is never exported. */
function p6Audit(string $name, bool $enforce = true): array
{
    $baseline = p6Read('database_baseline');
    $result = ['at' => now()->toAtomString(), 'rows' => [], 'quality_counts' => [], 'errors' => []];
    foreach (['documents', 'document_identifiers', 'document_contents', 'scrape_runs', 'scrape_run_items', 'failed_jobs', 'jobs', 'migrations',
        'scrape_campaigns', 'scrape_campaign_checkpoints', 'source_archive_blobs', 'source_archive_observations'] as $table) {
        $result['rows'][$table] = DB::table($table)->count();
    }
    foreach (['document_sources', 'documents', 'document_identifiers', 'document_contents', 'scrape_runs', 'scrape_run_items', 'failed_jobs'] as $table) {
        $present = [];
        foreach (DB::table($table)->whereIn('id', array_keys($baseline['record_hashes'][$table]))->orderBy('id')->get() as $row) {
            $data = (array) $row;
            if ($table === 'document_contents') {
                foreach (['quality_status', 'quality_issues', 'quality_checked_at', 'extraction_metadata', 'pending_candidate'] as $field) {
                    unset($data[$field]);
                }
                $old = $baseline['contents'][$row->id];
                if ($row->quality_status !== $old['quality_status'] || json_decode($row->quality_issues, true) !== $old['quality_issues']) {
                    $result['errors'][] = 'historical_quality_changed:'.$row->id;
                }
            }
            ksort($data);
            $present[$row->id] = true;
            if (hash('sha256', json_encode($data)) !== $baseline['record_hashes'][$table][$row->id]) {
                $result['errors'][] = 'historical_record_changed:'.$table.':'.$row->id;
            }
        }
        if (count($present) !== count($baseline['record_hashes'][$table])) {
            $result['errors'][] = 'historical_record_deleted:'.$table;
        }
    }
    foreach (DocumentContent::orderBy('id')->cursor() as $content) {
        $status = $content->quality_status;
        $result['quality_counts'][$status] = ($result['quality_counts'][$status] ?? 0) + 1;
        if (trim($content->content) === '' || hash('sha256', $content->content) !== $content->content_hash
            || ($status === 'verified' && $content->quality_issues !== [])) {
            $result['errors'][] = 'invalid_content:'.$content->id;
        }
    }
    $queries = [
        'duplicate_languages' => 'SELECT count(*) AS n FROM (SELECT document_id,language FROM document_contents GROUP BY document_id,language HAVING count(*)>1) x',
        'duplicate_aliases' => 'SELECT count(*) AS n FROM (SELECT source_id,external_id FROM document_identifiers GROUP BY source_id,external_id HAVING count(*)>1) x',
        'duplicate_canonical' => 'SELECT count(*) AS n FROM (SELECT source_id,external_id FROM documents GROUP BY source_id,external_id HAVING count(*)>1) x',
        'orphan_contents' => 'SELECT count(*) AS n FROM document_contents c LEFT JOIN documents d ON d.id=c.document_id WHERE d.id IS NULL',
        'orphan_identifiers' => 'SELECT count(*) AS n FROM document_identifiers i LEFT JOIN documents d ON d.id=i.document_id WHERE d.id IS NULL OR d.source_id<>i.source_id',
    ];
    foreach ($queries as $key => $query) {
        $result['checks'][$key] = (int) DB::selectOne($query)->n;
        if ($result['checks'][$key] !== 0) {
            $result['errors'][] = $key;
        }
    }
    foreach (p6Read('historical_baseline') as $file => $hash) {
        if (! is_file(base_path($file)) || hash_file('sha256', base_path($file)) !== $hash) {
            $result['errors'][] = 'historical_evidence_changed:'.$file;
        }
    }
    if (p6History() !== p6Read('legacy_campaign_hashes')) {
        $result['errors'][] = 'legacy_campaign_changed';
    }
    $result['archive'] = app(SourceArchive::class)->audit();
    if (! $result['archive']['ok']) {
        $result['errors'][] = 'archive_integrity_violation';
    }
    if ($result['rows']['failed_jobs'] !== $baseline['rows']['failed_jobs']) {
        $result['errors'][] = 'new_technical_failed_job';
    }
    $result['campaign'] = app(DurableCampaign::class)->state();
    $result['database_bytes'] = (int) DB::selectOne('SELECT pg_database_size(current_database()) AS n')->n;
    $result['disk_free_bytes'] = disk_free_space(storage_path('app'));
    $result['peak_php_bytes'] = memory_get_peak_usage(true);
    p6Save($name, $result);
    echo 'AUDIT '.$name.' docs='.$result['rows']['documents'].' texts='.$result['rows']['document_contents'].' errors='.count($result['errors'])."\n";
    flush();
    if ($result['errors'] && $enforce) {
        $campaign = app(DurableCampaign::class);
        if ($campaign->state()['owned_by_this_process'] ?? false) {
            $campaign->stop('database_or_archive_integrity_violation');
        }
        throw new RuntimeException('Integrity gate: '.implode(',', $result['errors']));
    }

    return $result;
}
function p6RunIds(string $campaignId): array
{
    return ScrapeRun::where('checkpoint->campaign_id', $campaignId)->orderBy('id')->pluck('id')->all();
}
function p6RunReport(int $runId): void
{
    $manager = app(ScrapeRunManager::class);
    $manager->reconcile($runId);
    $run = ScrapeRun::findOrFail($runId);
    p6Save('run_'.$runId, ['run' => $run->toArray(), 'summary' => $manager->summary($run), 'items' => $run->items()->orderBy('id')->get()->toArray()]);
}
function p6FinishFailure(Throwable $error): void
{
    $campaign = app(DurableCampaign::class);
    $state = $campaign->state();
    if (($state['owned_by_this_process'] ?? false) && in_array($state['status'], ['pausing', 'paused'], true)) {
        $campaign->pause();
    } elseif (($state['owned_by_this_process'] ?? false) && $state['status'] === 'running') {
        $campaign->stop('controller_exception:'.$error::class.':'.mb_substr($error->getMessage(), 0, 240));
    }
    p6Save('stop_diagnostic', ['at' => now()->toAtomString(), 'class' => $error::class, 'message' => $error->getMessage(), 'campaign' => $campaign->state()]);
    echo 'STOP '.$error->getMessage()."\n";
}
