<?php

use App\Services\Chunking\ChunkingPipeline;
use App\Services\Chunking\PassageVerifier;
use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

require dirname(__DIR__).'/vendor/autoload.php';
require __DIR__.'/ai_p3_evidence.php';
$root = dirname(__DIR__);
$dir = $root.'/docs/ai_p3';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
$status = app(ChunkingPipeline::class)->status((int) config('ai.chunk_size'));
if ($db->getDatabaseName() !== 'mening_fikrim_ai_dev' || app()->runningUnitTests() || $status['pending_retrieval_contents'] !== 0 || $status['queued'] !== 0 || is_file($dir.'/final_validation.json')) {
    throw new RuntimeException('Settled isolated full corpus and no existing final evidence required.');
}
$db->statement("SET application_name='ai-p3-final-validation'");
$db->statement('SET max_parallel_workers_per_gather=0');
$report = ['at' => now()->toIso8601String(), 'status' => $status, 'checked_contents' => 0, 'checked_source_chunks' => 0, 'checked_passages' => 0, 'source_bytes' => 0,
    'maximum_body_chars' => 0, 'maximum_input_chars' => 0, 'short_bodies' => 0, 'short_inputs' => 0, 'eligible' => 0, 'errors' => []];
$out = fopen($dir.'/content_validation.jsonl', 'w');
foreach ($db->table('document_contents')->orderBy('id')->pluck('id') as $id) {
    $check = app(PassageVerifier::class)->verify((int) $id);
    fwrite($out, json_encode($check, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    $report['checked_contents']++;
    $report['checked_source_chunks'] += $check['source_chunks'] ?? 0;
    $report['checked_passages'] += $check['passages'] ?? 0;
    foreach (['source_bytes', 'short_bodies', 'short_inputs', 'eligible'] as $key) {
        $report[$key] += $check[$key] ?? 0;
    }
    foreach (['maximum_body_chars', 'maximum_input_chars'] as $key) {
        $report[$key] = max($report[$key], $check[$key] ?? 0);
    }
    if (! $check['valid']) {
        $report['errors'][] = $check;
    }
    if ($report['checked_contents'] % 1000 === 0) {
        echo json_encode(['verified_contents' => $report['checked_contents'], 'errors' => count($report['errors'])])."\n";
        fflush($out);
        usleep(100000);
    }
}
fclose($out);
$report['source_preservation'] = p3Preservation($db, $root);
$report['quality'] = p3Quality($db);
$report['relations'] = p3Relations($db);
$report['indexes'] = $db->select("SELECT c.relname,pg_relation_size(c.oid) AS bytes,i.indisvalid FROM pg_class c JOIN pg_index i ON i.indexrelid=c.oid WHERE c.relname LIKE 'ai_%' ORDER BY c.relname");
$report['disk_free_bytes'] = disk_free_space($root);
$report['multiple_current_per_layer'] = count($db->select('SELECT document_content_id,layer FROM document_chunk_sets WHERE is_current GROUP BY 1,2 HAVING count(*)>1'));
$report['duplicate_passage_identity_groups'] = count($db->select('SELECT chunk_set_id,passage_index FROM retrieval_passages GROUP BY 1,2 HAVING count(*)>1'));
$report['duplicate_generation_identity_groups'] = count($db->select('SELECT document_content_id,profile_hash,input_fingerprint FROM document_chunk_sets GROUP BY 1,2,3 HAVING count(*)>1'));
$report['languages'] = $db->select('SELECT p.language,count(*) AS passages,count(DISTINCT p.document_content_id) AS contents FROM retrieval_passages p JOIN document_chunk_sets r ON r.id=p.chunk_set_id WHERE r.is_current GROUP BY 1 ORDER BY 1');
$report['categories'] = $db->select('SELECT d.document_type,count(*) AS passages,count(DISTINCT d.id) AS documents FROM retrieval_passages p JOIN document_chunk_sets r ON r.id=p.chunk_set_id JOIN documents d ON d.id=p.document_id WHERE r.is_current GROUP BY 1 ORDER BY 1');
$report['oversized_bodies'] = $db->table('retrieval_passages')->whereRaw('char_length(body)>?', [config('ai.passage_max_chars')])->count();
$report['oversized_inputs'] = $db->table('retrieval_passages')->whereRaw("(metadata->'embedding_input'->>'characters')::int>?", [config('ai.embedding_max_chars')])->count();
$report['missing_source_urls'] = $db->table('retrieval_passages')->whereRaw("metadata->>'source_url' IS NULL")->count();
$before = p3RowsHash($db, 'retrieval_passages', 250);
$exit = Artisan::call('legal:chunk-documents', ['--only-pending' => true, '--content-limit' => 100]);
$after = p3RowsHash($db, 'retrieval_passages', 250);
$report['repeat_command'] = ['exit_code' => $exit, 'output' => trim(Artisan::output()), 'all_passage_rows_unchanged' => $before === $after, 'rows_hash' => $after];
$phases = json_decode(file_get_contents($dir.'/phases.json'), true, flags: JSON_THROW_ON_ERROR);
$selected = [];
$workerStats = [];
foreach ($phases as $phase) {
    if ($phase['status'] !== 'completed' || $phase['profile_hash'] !== $status['retrieval_profile_hash']) {
        $report['errors'][] = 'phase_incomplete_or_profile_changed';
    }
    foreach ($phase['batches'] as $batch) {
        $selected = [...$selected, ...$batch['content_ids']];
        $workerStats[] = $batch['worker_resources'];
    }
}
$report['selection_count'] = count($selected);
$report['distinct_selection_count'] = count(array_unique($selected));
$report['worker_resources'] = ['elapsed_seconds_sum' => array_sum(array_column($workerStats, 'elapsed_seconds')), 'user_seconds_sum' => array_sum(array_column($workerStats, 'user_seconds')), 'system_seconds_sum' => array_sum(array_column($workerStats, 'system_seconds')), 'maximum_rss_kb' => max(array_column($workerStats, 'maximum_rss_kb'))];
$report['skipped_contents'] = 0;
$report['human_review_performed'] = false;
foreach ($report['source_preservation'] as $table => $check) {
    if (! $check['unchanged']) {
        $report['errors'][] = 'mutated_'.$table;
    }
}
if ($report['checked_contents'] !== $status['contents'] || $report['selection_count'] !== $status['contents'] || $report['distinct_selection_count'] !== $status['contents']
    || $report['checked_passages'] !== $status['current_passages'] || $status['failed_retrieval_generations'] !== 0 || $report['multiple_current_per_layer'] !== 0
    || $report['duplicate_passage_identity_groups'] !== 0 || $report['duplicate_generation_identity_groups'] !== 0 || $report['oversized_bodies'] !== 0 || $report['oversized_inputs'] !== 0
    || $exit !== 0 || ! $report['repeat_command']['all_passage_rows_unchanged']) {
    $report['errors'][] = 'identity_coverage_limits_or_idempotence';
}
$report['finished_at'] = now()->toIso8601String();
$report['passed'] = $report['errors'] === [];
file_put_contents($dir.'/final_validation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['passed' => $report['passed'], 'contents' => $report['checked_contents'], 'passages' => $report['checked_passages'], 'errors' => count($report['errors'])])."\n";
exit($report['passed'] ? 0 : 1);
