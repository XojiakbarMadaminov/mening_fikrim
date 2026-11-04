<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\Chunking\ChunkingPipeline;
use App\Services\Chunking\PassageVerifier;
use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

$options = getopt('', ['phase:']);
$phase = $options['phase'] ?? '';
if (! in_array($phase, ['pilot100', 'pilot1000', 'full'], true)) {
    throw new RuntimeException('Provide --phase=pilot100|pilot1000|full');
}
$root = dirname(__DIR__);
$dir = $root.'/docs/ai_p3';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
if (app()->runningUnitTests() || $db->getDatabaseName() !== 'mening_fikrim_ai_dev') {
    throw new RuntimeException('Operator acceptance only in isolated AI DEV.');
}
$db->statement("SET application_name = 'ai-p3-coordinator'");
$db->statement("SET lock_timeout = '5s'");
$db->statement("SET statement_timeout = '120s'");
$db->statement('SET max_parallel_workers_per_gather = 0');
if (! $db->selectOne('SELECT pg_try_advisory_lock(1095320912,1) AS acquired')->acquired) {
    throw new RuntimeException('Another AI corpus coordinator owns this work.');
}
$service = app(ChunkingPipeline::class);
$verifier = app(PassageVerifier::class);
$size = (int) config('ai.chunk_size');
$profile = $service->profile($size);
$path = $dir.'/phases.json';
$phases = is_file($path) ? json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR) : [];
$save = function () use (&$phases, $path) {
    $tmp = $path.'.tmp';
    file_put_contents($tmp, json_encode($phases, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    rename($tmp, $path);
};
if (($phases[$phase]['status'] ?? null) === 'completed') {
    echo "Phase already completed; no writes or dispatch.\n";
    exit(0);
}
if ($phase !== 'pilot100' && ($phases['pilot100']['status'] ?? null) !== 'completed') {
    throw new RuntimeException('pilot100 gate is incomplete.');
}
if ($phase === 'full' && (($phases['pilot1000']['status'] ?? null) !== 'completed' || ! is_file($dir.'/quality_gate.json') || ! (json_decode(file_get_contents($dir.'/quality_gate.json'), true)['passed'] ?? false))) {
    throw new RuntimeException('pilot1000 gate is incomplete.');
}
if (! isset($phases[$phase])) {
    $initial = $service->status($size);
    $goal = $phase === 'pilot100' ? 100 : ($phase === 'pilot1000' ? 1000 : $initial['pending_retrieval_contents']);
    $phases[$phase] = ['status' => 'running', 'started_at' => now()->toIso8601String(), 'profile_hash' => $profile, 'initial' => $initial, 'target_additional_contents' => $goal, 'validated_contents' => 0, 'batches' => [], 'inflight' => null];
    $save();
}
if ($phases[$phase]['profile_hash'] !== $profile) {
    throw new RuntimeException('Profile changed; refusing to mix phases.');
}
$phases[$phase]['status'] = 'running';
$save();
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$stop) {
        $stop = true;
    });
    pcntl_signal(SIGINT, function () use (&$stop) {
        $stop = true;
    });
}
try {
    while ($phases[$phase]['validated_contents'] < $phases[$phase]['target_additional_contents']) {
        preg_match('/MemAvailable:\s+(\d+)/', file_get_contents('/proc/meminfo'), $available);
        if ((int) ($available[1] ?? 0) < 3 * 1024 * 1024 || disk_free_space($root) < 10 * 1024 ** 3) {
            throw new RuntimeException('AI-only safety pause: low available RAM/disk.');
        }
        if ($stop) {
            throw new RuntimeException('Operator signal: stopped between bounded batches.');
        }
        if (! $phases[$phase]['inflight']) {
            if ($db->table('ai_jobs')->exists()) {
                throw new RuntimeException('Unexpected existing AI queue receipts; inspect before dispatch.');
            }
            $remaining = $phases[$phase]['target_additional_contents'] - $phases[$phase]['validated_contents'];
            $query = $service->pendingSources($size);
            if ($phase === 'pilot100') {
                $sample = json_decode(file_get_contents($dir.'/pilot_sample.json'), true, flags: JSON_THROW_ON_ERROR);
                $query->whereIn('c.id', $sample['content_ids']);
            }
            $ids = $query->orderBy('c.id')->limit(min(100, $remaining))->pluck('c.id')->all();
            if ($ids === []) {
                throw new RuntimeException('Pending set exhausted before the recorded phase goal.');
            }
            $number = count($phases[$phase]['batches']) + 1;
            $label = $phase.'-'.str_pad((string) $number, 4, '0', STR_PAD_LEFT);
            $phases[$phase]['inflight'] = ['label' => $label, 'content_ids' => $ids, 'planned_at' => now()->toIso8601String(), 'state' => 'planned'];
            $save();
            $exit = Artisan::call('legal:chunk-documents', ['--content-id' => $ids, '--content-limit' => count($ids), '--limit' => 500, '--only-pending' => true, '--queue' => true]);
            file_put_contents($root.'/storage/logs/ai_p3_dispatch.log', Artisan::output(), FILE_APPEND);
            if ($exit !== 0) {
                throw new RuntimeException('Bounded dispatch failed.');
            }
            $phases[$phase]['inflight']['state'] = 'queued';
            $save();
        }
        $inflight = $phases[$phase]['inflight'];
        $ids = $inflight['content_ids'];
        $label = $inflight['label'];
        $resource = $dir.'/'.$label.'-resources.json';
        $command = ['nice', '-n', '10', 'ionice', '-c', '2', '-n', '7', '/usr/bin/time', '-f', '{"elapsed_seconds":%e,"user_seconds":%U,"system_seconds":%S,"maximum_rss_kb":%M,"cpu_percent":"%P"}', '-o', $resource,
            PHP_BINARY, '-d', 'memory_limit=384M', 'artisan', 'legal:work', '--max-jobs='.min(500, count($ids) * 3), '--rest-ms=10', '--no-ansi'];
        $process = new Process($command, $root);
        $process->setTimeout(1200);
        $log = fopen($root.'/storage/logs/ai_p3_worker.log', 'a');
        $code = $process->run(function ($type, $bytes) use ($log) {
            fwrite($log, $bytes);
        });
        fclose($log);
        if ($code !== 0 || $db->table('ai_jobs')->exists()) {
            throw new RuntimeException('Worker stopped with nonzero exit or preserved unfinished receipts.');
        }
        $checks = [];
        foreach ($ids as $id) {
            $check = $verifier->verify((int) $id, $size);
            $checks[] = $check;
            if (! $check['valid']) {
                throw new RuntimeException('Post-batch verification failed for content '.$id.': '.json_encode($check['errors']));
            }
        }
        $batch = ['label' => $label, 'finished_at' => now()->toIso8601String(), 'content_ids' => $ids, 'validated_contents' => count($checks), 'passages' => array_sum(array_column($checks, 'passages')),
            'maximum_body_chars' => max(array_column($checks, 'maximum_body_chars')), 'maximum_input_chars' => max(array_column($checks, 'maximum_input_chars')),
            'short_bodies' => array_sum(array_column($checks, 'short_bodies')), 'short_inputs' => array_sum(array_column($checks, 'short_inputs')),
            'source_bytes' => array_sum(array_column($checks, 'source_bytes')), 'errors' => [], 'worker_resources' => json_decode(trim(file_get_contents($resource)), true, flags: JSON_THROW_ON_ERROR)];
        $phases[$phase]['batches'][] = $batch;
        $phases[$phase]['validated_contents'] += count($checks);
        $phases[$phase]['inflight'] = null;
        $phases[$phase]['latest'] = $service->status($size);
        $save();
        echo json_encode(['phase' => $phase, 'batch' => $label, 'phase_validated' => $phases[$phase]['validated_contents'], 'current_contents' => $phases[$phase]['latest']['processed_retrieval_contents'], 'pending' => $phases[$phase]['latest']['pending_retrieval_contents'], 'current_passages' => $phases[$phase]['latest']['current_passages'], 'resources' => $batch['worker_resources']], JSON_UNESCAPED_SLASHES)."\n";
        usleep(250000);
    }
    $phases[$phase]['status'] = 'completed';
    $phases[$phase]['finished_at'] = now()->toIso8601String();
    $phases[$phase]['latest'] = $service->status($size);
    $save();
} catch (Throwable $e) {
    $phases[$phase]['status'] = 'stopped';
    $phases[$phase]['stop_reason'] = $e->getMessage();
    $phases[$phase]['stopped_at'] = now()->toIso8601String();
    $save();
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
} finally {
    $db->selectOne('SELECT pg_advisory_unlock(1095320912,1)');
}
