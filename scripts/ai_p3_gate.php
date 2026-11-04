<?php

use App\Services\Chunking\ChunkingPipeline;
use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/ai_p3_evidence.php';
$root = dirname(__DIR__);
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
if ($db->getDatabaseName() !== 'mening_fikrim_ai_dev' || app()->runningUnitTests()) {
    throw new RuntimeException('Isolated AI acceptance only.');
}
$db->statement("SET application_name='ai-p3-quality-gate'");
$db->statement('SET max_parallel_workers_per_gather=0');
$phases = json_decode(file_get_contents($root.'/docs/ai_p3/phases.json'), true, flags: JSON_THROW_ON_ERROR);
$report = ['at' => now()->toIso8601String(), 'status' => app(ChunkingPipeline::class)->status((int) config('ai.chunk_size')), 'errors' => []];
$selected = [];
$batches = [];
foreach (['pilot100' => 100, 'pilot1000' => 1000] as $phase => $goal) {
    if (($phases[$phase]['status'] ?? null) !== 'completed' || $phases[$phase]['validated_contents'] !== $goal) {
        throw new RuntimeException('Pilot not completed exactly.');
    }
    foreach ($phases[$phase]['batches'] as $batch) {
        $batches[] = $batch;
        $selected = [...$selected, ...$batch['content_ids']];
        if ($batch['errors'] || $batch['maximum_body_chars'] > config('ai.passage_max_chars') || $batch['maximum_input_chars'] > config('ai.embedding_max_chars')) {
            $report['errors'][] = $batch['label'];
        }
    }
}
$report['source_preservation'] = p3Preservation($db, $root);
foreach ($report['source_preservation'] as $table => $check) {
    if (! $check['unchanged']) {
        $report['errors'][] = 'mutated_'.$table;
    }
}
$report['multiple_current_per_layer'] = count($db->select('SELECT document_content_id,layer FROM document_chunk_sets WHERE is_current GROUP BY 1,2 HAVING count(*)>1'));
$report['distinct_pilot_sources'] = count(array_unique($selected));
$report['source_comparison'] = $db->table('document_chunks as k')->join('document_chunk_sets as s', 's.id', '=', 'k.chunk_set_id')->where('s.layer', 'source')->where('s.is_current', true)->whereIn('k.document_content_id', $selected)
    ->selectRaw('count(*) AS chunks,max(char_length(k.content)) AS maximum,sum((char_length(k.content)<80)::int) AS short_bodies,sum((char_length(k.content)>4000)::int) AS oversized')->first();
$report['retrieval_comparison'] = ['passages' => array_sum(array_column($batches, 'passages')), 'maximum_body_chars' => max(array_column($batches, 'maximum_body_chars')), 'maximum_input_chars' => max(array_column($batches, 'maximum_input_chars')), 'short_bodies' => array_sum(array_column($batches, 'short_bodies')), 'short_inputs' => array_sum(array_column($batches, 'short_inputs'))];
$report['quality'] = p3Quality($db);
$report['relations'] = p3Relations($db);
$before = json_decode(file_get_contents($root.'/docs/ai_p3/before.json'), true, flags: JSON_THROW_ON_ERROR);
$baseline = array_sum(array_map(fn ($r) => $r['table_bytes'] + $r['index_bytes'], $before['relations']));
$now = array_sum(array_map(fn ($r) => $r->table_bytes + $r->index_bytes, $report['relations']));
$report['measured_pilot_growth_bytes'] = $now - $baseline;
$report['source_bytes_processed'] = array_sum(array_column($batches, 'source_bytes'));
$report['projected_full_growth_bytes_with_2x_margin'] = (int) ceil(2 * ($now - $baseline) * 651169442 / $report['source_bytes_processed']);
$report['disk_free_bytes'] = disk_free_space($root);
$report['worker_maximum_rss_kb'] = max(array_column(array_column($batches, 'worker_resources'), 'maximum_rss_kb'));
$report['controls'] = 'One AI worker, CPU 19, nice 10, ionice 2/7, 100-source batches, source transaction, RAM>=3GiB/disk>=10GiB; MAIN observed read-only, no parser/source controls invoked.';
if ($report['distinct_pilot_sources'] !== 1100 || $report['multiple_current_per_layer'] !== 0 || $report['status']['failed_retrieval_generations'] !== 0 || $report['status']['queued'] !== 0 || $report['disk_free_bytes'] < $report['projected_full_growth_bytes_with_2x_margin'] + 10 * 1024 ** 3) {
    $report['errors'][] = 'identity_failure_queue_or_capacity';
}
$report['passed'] = $report['errors'] === [];
$file = $root.'/docs/ai_p3/quality_gate.json';
if (is_file($file)) {
    throw new RuntimeException('Pilot quality gate evidence already exists.');
}
file_put_contents($file, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
exit($report['passed'] ? 0 : 1);
