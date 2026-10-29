<?php

/** Controlled image only; no HTTP or database writes. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__, 2).'/tests/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active_test') {
    throw new RuntimeException('Controlled OCR check requires the fixed test environment.');
}
$temp = trim(file_get_contents(storage_path('app/p3_benchmark_path')));
$results = [];
foreach ([1, 2] as $parallelism) {
    $start = hrtime(true);
    $processes = [];
    for ($i = 0; $i < 2; $i++) {
        $directory = $temp.'/ocr-'.$parallelism.'-'.$i;
        File::ensureDirectoryExists($directory, 0700);
        $process = new Process(['/usr/bin/time', '-f', '%U %S %M', '-o', $directory.'/resource.txt', config('scraping.tesseract_binary'), $temp.'/controlled_ocr.png', 'out', '-l', 'uzb_cyrl+uzb', '--dpi', '200', 'txt', 'tsv'], $directory,
            ['OMP_THREAD_LIMIT' => '1', 'TESSDATA_PREFIX' => config('scraping.tessdata_prefix'), 'LD_LIBRARY_PATH' => config('scraping.process_library_path')], timeout: 45);
        $process->start();
        $processes[] = $process;
        if ($parallelism === 1) {
            $process->wait();
        }
    }
    foreach ($processes as $process) {
        $process->wait();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Controlled OCR process failed.');
        }
    }
    $profiles = [];
    for ($i = 0; $i < 2; $i++) {
        [$user, $system, $rss] = explode(' ', trim(file_get_contents($temp.'/ocr-'.$parallelism.'-'.$i.'/resource.txt')));
        $profiles[] = ['user_cpu_seconds' => (float) $user, 'system_cpu_seconds' => (float) $system, 'peak_rss_kib' => (int) $rss];
    }
    $results[] = ['offline_controlled_image' => true, 'parallelism' => $parallelism, 'tasks' => 2, 'wall_seconds' => (hrtime(true) - $start) / 1e9, 'profiles' => $profiles];
    echo 'Controlled OCR parallelism '.$parallelism.' completed two tasks in '.round(end($results)['wall_seconds'], 3)." s\n";
}
file_put_contents(__DIR__.'/ocr_contention.json', json_encode($results, JSON_PRETTY_PRINT)."\n");
