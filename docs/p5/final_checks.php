<?php

/** Final read-only preflight/data/test-schema audit. No HTTP and no schema mutations. */
use App\Services\Scraping\PilotCampaign;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$summary = json_decode(file_get_contents(__DIR__.'/final_summary.json'), true, flags: JSON_THROW_ON_ERROR);
$result = ['database' => DB::selectOne('select current_database() as name')->name, 'counts' => [], 'defaults' => [
    'live' => config('scraping.live_enabled'), 'scheduler' => config('scraping.scheduler_enabled'),
    'workers' => config('scraping.workers'), 'delay_ms' => config('scraping.request_delay_ms'), 'per_run_requests' => config('scraping.request_budget'),
], 'campaign' => app(PilotCampaign::class)->state(), 'temp_files' => [], 'env_permissions' => []];
if ($result['database'] !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development database required.');
}
foreach (['documents', 'document_identifiers', 'document_contents', 'scrape_runs', 'scrape_run_items', 'jobs', 'failed_jobs', 'migrations'] as $table) {
    $result['counts'][$table] = DB::table($table)->count();
}
$testConnection = config('database.connections.pgsql');
$testConnection['database'] = 'mening_fikrim_active_test';
config(['database.connections.p5_test_audit' => $testConnection]);
$test = DB::connection('p5_test_audit');
$result['test_database'] = $test->selectOne('select current_database() as name')->name;
foreach (['documents', 'document_sources', 'migrations', 'jobs', 'failed_jobs'] as $table) {
    $result['test_counts'][$table] = $test->table($table)->count();
}
foreach (['.env', '.env.testing'] as $file) {
    $result['env_permissions'][$file] = substr(sprintf('%o', fileperms(base_path($file))), -4);
}
$temp = config('scraping.temp_directory');
if (is_dir($temp)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $result['temp_files'][] = $file->getPathname();
        }
    }
}
$result['pass'] = $result['counts']['documents'] === $summary['total_documents']
    && $result['counts']['document_contents'] === $summary['total_contents']
    && $result['counts']['failed_jobs'] === 24 && $result['counts']['jobs'] === 0
    && $result['defaults'] === ['live' => false, 'scheduler' => false, 'workers' => 1, 'delay_ms' => 2000, 'per_run_requests' => 400]
    && $result['campaign']['used'] === $summary['http_requests'] && $result['campaign']['stopped'] !== null
    && $result['test_database'] === 'mening_fikrim_active_test'
    && $result['test_counts'] === ['documents' => 0, 'document_sources' => 2, 'migrations' => 9, 'jobs' => 0, 'failed_jobs' => 0]
    && $result['temp_files'] === [];
file_put_contents(__DIR__.'/final_checks.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($result['pass'] ? 0 : 1);
