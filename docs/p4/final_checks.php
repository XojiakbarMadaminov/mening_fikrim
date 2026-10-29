<?php

/** Read-only P4 environment/database checks; credentials are never serialized. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Read-only final checks require fixed development DB.');
}
$names = array_column(DB::select("select datname from pg_database where not datistemplate and datallowconn and datname like 'mening_fikrim%' order by datname"), 'datname');
$old = json_decode(file_get_contents(dirname(__DIR__).'/p3/database_after.json'), true, flags: JSON_THROW_ON_ERROR);
$config = DB::connection()->getConfig();
$test = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname=mening_fikrim_active_test', $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$test->beginTransaction();
$test->exec('SET TRANSACTION READ ONLY');
$testRows = [];
foreach (['migrations', 'documents', 'document_sources', 'document_contents', 'scrape_run_items', 'jobs', 'failed_jobs'] as $table) {
    $testRows[$table] = (int) $test->query('select count(*) from "'.$table.'"')->fetchColumn();
}
$test->rollBack();
$tables = Schema::getTableListing(schemaQualified: false);
sort($tables);
$expected = ['cache', 'cache_locks', 'document_contents', 'document_identifiers', 'document_sources', 'documents', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'scrape_run_items', 'scrape_runs'];
sort($expected);
$result = ['database_names_unchanged' => $names === $old['databases'], 'same_six_business_tables' => $tables === $expected,
    'test_rows' => $testRows, 'live_enabled' => config('scraping.live_enabled'), 'scheduler_enabled' => config('scraping.scheduler_enabled'),
    'workers' => config('scraping.workers'), 'request_delay_ms' => config('scraping.request_delay_ms'), 'max_documents_per_run' => config('scraping.max_documents_per_run'),
    'queue_jobs' => DB::table('jobs')->count(), 'open_items' => DB::table('scrape_run_items')->whereNotIn('status', ['imported', 'unchanged', 'review', 'failed', 'skipped_alias'])->count(),
    'env_permissions' => ['.env' => sprintf('%04o', fileperms(base_path('.env')) & 0777), '.env.testing' => sprintf('%04o', fileperms(base_path('.env.testing')) & 0777)]];
if (! $result['database_names_unchanged'] || ! $result['same_six_business_tables'] || $testRows !== ['migrations' => 9, 'documents' => 0, 'document_sources' => 2, 'document_contents' => 0, 'scrape_run_items' => 0, 'jobs' => 0, 'failed_jobs' => 0]
    || $result['live_enabled'] || $result['scheduler_enabled'] || $result['workers'] !== 1 || $result['request_delay_ms'] < 2000 || $result['max_documents_per_run'] !== 30 || $result['queue_jobs'] !== 0 || $result['open_items'] !== 0
    || $result['env_permissions'] !== ['.env' => '0600', '.env.testing' => '0600']) {
    throw new RuntimeException('P4 final environment invariant failed.');
}
$result['all_checks_passed'] = true;
file_put_contents(__DIR__.'/final_checks.json', json_encode($result, JSON_PRETTY_PRINT)."\n");
echo json_encode($result, JSON_PRETTY_PRINT)."\n";
