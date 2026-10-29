<?php

/** Read-only final P3 checks; never print credentials. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development database required for read-only final checks.');
}
$rows = [];
foreach (['documents', 'document_identifiers', 'document_contents', 'scrape_runs', 'scrape_run_items', 'jobs', 'failed_jobs'] as $table) {
    $rows[$table] = DB::table($table)->count();
}
$result = [
    'development_database' => 'mening_fikrim_active',
    'rows' => $rows,
    'ledger_statuses' => DB::table('scrape_run_items')->selectRaw('status, count(*) as count')->groupBy('status')->orderBy('status')->get()->toArray(),
    'open_items' => DB::table('scrape_run_items')->whereNotIn('status', ['imported', 'unchanged', 'review', 'failed', 'skipped_alias'])->count(),
    'live_enabled' => config('scraping.live_enabled'),
    'scheduler_enabled' => config('scraping.scheduler_enabled'),
    'workers' => config('scraping.workers'),
    'request_delay_ms' => config('scraping.request_delay_ms'),
    'max_documents_per_run' => config('scraping.max_documents_per_run'),
    'request_budget' => config('scraping.request_budget'),
    'env_permissions' => [],
    'temporary_pointer_files_remaining' => [],
];
foreach (['.env', '.env.testing'] as $name) {
    $result['env_permissions'][$name] = sprintf('%04o', fileperms(base_path($name)) & 0777);
}
foreach (['p3_live_path', 'p3_benchmark_path'] as $name) {
    if (is_file(storage_path('app/'.$name))) {
        $result['temporary_pointer_files_remaining'][] = $name;
    }
}
if ($rows !== ['documents' => 16, 'document_identifiers' => 48, 'document_contents' => 40, 'scrape_runs' => 7, 'scrape_run_items' => 112, 'jobs' => 0, 'failed_jobs' => 24]
    || $result['open_items'] !== 0 || $result['live_enabled'] || $result['scheduler_enabled']
    || $result['workers'] !== 1 || $result['request_delay_ms'] < 2000 || $result['max_documents_per_run'] !== 30
    || $result['env_permissions'] !== ['.env' => '0600', '.env.testing' => '0600']
    || $result['temporary_pointer_files_remaining'] !== []) {
    throw new RuntimeException('P3 final invariant failed; inspect read-only audit.');
}
$result['all_checks_passed'] = true;
file_put_contents(__DIR__.'/final_checks.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
