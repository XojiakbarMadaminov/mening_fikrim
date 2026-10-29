<?php

use App\Jobs\DiscoverDocumentsJob;
use App\Models\DocumentSource;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__, 2).'/tests/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active_test' || config('scraping.live_enabled')) {
    throw new RuntimeException('Offline fixed test environment required.');
}
Artisan::call('migrate', ['--seed' => true, '--force' => true]);
if (DB::table('jobs')->exists()) {
    throw new RuntimeException('Drain test queue first.');
}
$manager = app(ScrapeRunManager::class);
$run = $manager->start(DocumentSource::where('slug', 'lex')->firstOrFail(), 'full', 3, ['8506802', '-8506802', '8506870']);
$manager->explicitSelection($run->id);
DB::table('jobs')->whereRaw("payload::jsonb ->> 'displayName' = ?", [DiscoverDocumentsJob::class.'#'.$run->id])->delete();
$workers = [];
for ($i = 0; $i < 2; $i++) {
    $process = new Process([PHP_BINARY, base_path('tests/Support/benchmark_worker.php')], base_path(), ['APP_ENV' => 'testing', 'SCRAPING_LIVE_ENABLED' => 'false', 'P3_BENCH_INTERVAL' => '2000', 'P3_BENCH_ALIAS' => '1', 'SCRAPING_LOG_PATH' => storage_path('app/p3-alias-race.log')], timeout: 60);
    $process->start();
    $workers[] = $process;
}
$profiles = [];
foreach ($workers as $worker) {
    $worker->wait();
    if (! $worker->isSuccessful()) {
        throw new RuntimeException($worker->getErrorOutput());
    } $profiles[] = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
}
$manager->reconcile($run->id);
$summary = $manager->summary($run->fresh());
if ($summary['processed'] !== 3 || $summary['created'] !== 1 || $summary['skipped_alias'] !== 2 || $summary['canonical_document_groups'] !== 1 || $summary['valid_content_count'] !== 3 || $summary['technical_failed'] !== 0) {
    throw new RuntimeException('Parallel canonical alias reconciliation failed: '.json_encode($summary));
}
file_put_contents(__DIR__.'/parallel_alias.json', json_encode(['offline' => true, 'summary' => $summary, 'items' => $run->items()->orderBy('id')->get()->toArray(), 'worker_profiles' => $profiles], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo "Two real workers: 3 source IDs, 1 canonical document, 1 created, 2 skipped_alias, 3 valid contents, 0 technical failures.\n";
