<?php

/** Explicit offline benchmark: fixed TEST DB, fake HTTP in every child, no live source traffic. */
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
    throw new RuntimeException('Offline benchmark requires fixed testing DB and live disabled.');
}
Artisan::call('migrate', ['--seed' => true, '--force' => true]);
if (DB::table('jobs')->exists()) {
    throw new RuntimeException('Finish outstanding test queue work before benchmarking.');
}
$source = DocumentSource::where('slug', 'lex')->firstOrFail();
$all = [];
foreach ([[1, 2000], [2, 2000], [1, 1000], [2, 1000]] as $case => [$workers, $interval]) {
    $base = 99000000 + ((int) DB::table('scrape_runs')->max('id') + 1) * 1000;
    $ids = array_map(fn ($i) => (string) ($base + $i), range(0, 5));
    $run = app(ScrapeRunManager::class)->start($source, 'full', count($ids), $ids);
    // Preload all import receipts before spawning --stop-when-empty workers; otherwise
    // the second worker can legitimately stop while the only discovery job is reserved.
    app(ScrapeRunManager::class)->explicitSelection($run->id);
    DB::table('jobs')->whereRaw("payload::jsonb ->> 'displayName' = ?", [DiscoverDocumentsJob::class.'#'.$run->id])->delete();
    $log = storage_path('app/p3-benchmark-'.$run->id.'.log');
    $start = hrtime(true);
    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $process = new Process([PHP_BINARY, base_path('tests/Support/benchmark_worker.php')], base_path(),
            ['APP_ENV' => 'testing', 'SCRAPING_LIVE_ENABLED' => 'false', 'P3_BENCH_INTERVAL' => (string) $interval,
                'P3_BENCH_IMAGE' => getenv('P3_BENCH_IMAGE'), 'SCRAPING_LOG_PATH' => $log], timeout: 240);
        $process->start();
        $processes[] = $process;
    }
    $lockSamples = 0;
    do {
        $running = false;
        foreach ($processes as $process) {
            $running = $process->isRunning() || $running;
            $process->checkTimeout();
        }
        $lockSamples += (int) DB::selectOne("select count(*) AS n from pg_stat_activity where application_name like 'p3_bench_%' and wait_event_type='Lock'")->n;
        usleep(100000);
    } while ($running);
    $profiles = [];
    foreach ($processes as $index => $process) {
        file_put_contents(__DIR__.'/benchmark_'.$case.'_worker_'.$index.'.txt', $process->getOutput().$process->getErrorOutput());
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Benchmark child failed; inspect saved output.');
        }
        $profiles[] = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);
    }
    $seconds = (hrtime(true) - $start) / 1e9;
    app(ScrapeRunManager::class)->reconcile($run->id);
    $summary = app(ScrapeRunManager::class)->summary($run->fresh());
    $daily = preg_replace('/\.log$/', '-'.date('Y-m-d').'.log', $log);
    $requests = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR)['context'], file($daily, FILE_IGNORE_NEW_LINES));
    $gaps = [];
    foreach ($requests as $i => $request) {
        if ($i > 0) {
            $gaps[] = $request['start_monotonic'] - $requests[$i - 1]['start_monotonic'];
        }
    }
    if ($summary['processed'] !== 6 || $summary['technical_failed'] !== 0 || DB::table('jobs')->exists() || min($gaps) < $interval / 1000) {
        throw new RuntimeException('Benchmark correctness/pacing gate failed.');
    }
    $all[] = ['offline' => true, 'workers' => $workers, 'interval_ms' => $interval, 'wall_seconds' => $seconds,
        'request_count' => count($requests), 'minimum_start_interval_seconds' => min($gaps), 'requests_per_second' => count($requests) / $seconds,
        'valid_documents_per_hour' => ($summary['imported'] + $summary['unchanged']) * 3600 / $seconds,
        'review_documents_per_hour' => $summary['review_required'] * 3600 / $seconds,
        'summary' => $summary, 'requests' => $requests, 'workers_profile' => $profiles, 'lock_wait_samples_at_100ms' => $lockSamples,
        'items' => $run->items()->orderBy('id')->get()->toArray()];
    file_put_contents(__DIR__.'/benchmark_results.json', json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    echo 'OFFLINE workers='.$workers.' interval='.$interval.' ms; '.round($seconds, 3).' s; valid='.($summary['imported'] + $summary['unchanged']).' review='.$summary['review_required'].' min_gap='.round(min($gaps), 6)."\n";
    flush();
}
