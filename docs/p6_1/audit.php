<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT current_database() AS name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development database required.');
}
$tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename");
$result = ['observed_at' => now()->toAtomString(), 'database' => 'mening_fikrim_active', 'counts' => [], 'record_hashes' => [], 'historical_files' => []];
foreach ($tables as $table) {
    $name = $table->tablename;
    $result['counts'][$name] = DB::table($name)->count();
    if (in_array($name, ['cache', 'cache_locks', 'jobs', 'job_batches', 'migrations'])) {
        continue;
    }
    foreach (DB::table($name)->orderBy('id')->cursor() as $row) {
        $result['record_hashes'][$name][$row->id] = hash('sha256', json_encode($row));
    }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__).'/p6', FilesystemIterator::SKIP_DOTS)) as $file) {
    $result['historical_files'][substr($file->getPathname(), strlen(base_path()) + 1)] = hash_file('sha256', $file->getPathname());
}
$result['historical_files']['docs/P6_REPORT.md'] = hash_file('sha256', dirname(__DIR__).'/P6_REPORT.md');
$result['quality'] = DB::table('document_contents')->selectRaw('quality_status,count(*) AS n')->groupBy('quality_status')->pluck('n', 'quality_status')->all();
$result['campaigns'] = DB::table('scrape_campaigns')->get(['campaign_id', 'status', 'stop_reason', 'used_http_requests'])->toArray();
$mode = $argv[1] ?? 'check';
$baselinePath = __DIR__.'/baseline.json';
if ($mode === 'baseline') {
    if (is_file($baselinePath)) {
        throw new RuntimeException('Never overwrite baseline.');
    }
    file_put_contents($baselinePath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
} else {
    $baseline = json_decode(file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
    $errors = [];
    foreach ($baseline['record_hashes'] as $table => $rows) {
        foreach ($rows as $id => $hash) {
            if (($result['record_hashes'][$table][$id] ?? null) !== $hash) {
                $errors[] = $table.':'.$id;
            }
        }
    }
    foreach ($baseline['historical_files'] as $file => $hash) {
        if (hash_file('sha256', base_path($file)) !== $hash) {
            $errors[] = $file;
        }
    }
    $result = ['observed_at' => $result['observed_at'], 'counts' => $result['counts'], 'quality' => $result['quality'], 'errors' => $errors];
    file_put_contents(__DIR__.'/preservation.json', json_encode($result, JSON_PRETTY_PRINT)."\n");
    if ($errors !== []) {
        throw new RuntimeException('Preservation failure: '.json_encode($errors));
    }
}
echo json_encode(['counts' => $result['counts'], 'quality' => $result['quality'], 'errors' => $result['errors'] ?? []], JSON_PRETTY_PRINT)."\n";
