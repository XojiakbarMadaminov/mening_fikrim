<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\ChunkDocumentService;
use App\Services\Legal\LegalChunkVerifier;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__);
$dir = $root.'/docs/ai_p2';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
if (app()->runningUnitTests() || $db->getDatabaseName() !== 'mening_fikrim_ai_dev') {
    throw new RuntimeException('Full acceptance only in isolated AI DEV.');
}
$service = app(ChunkDocumentService::class);
$verifier = app(LegalChunkVerifier::class);
$status = $service->status((int) config('ai.chunk_size'));
if ($status['queued'] !== 0 || $status['pending_contents'] !== 0) {
    throw new RuntimeException('Full generation must settle before final validation.');
}
$db->statement("SET application_name = 'ai-p2-validation'");
$db->statement('SET max_parallel_workers_per_gather = 0');
$report = ['at' => now()->toIso8601String(), 'status' => $status, 'errors' => [], 'checked_contents' => 0, 'checked_chunks' => 0, 'source_bytes' => 0];
$out = fopen($dir.'/content_validation.jsonl', 'w');
foreach ($db->table('document_contents')->orderBy('id')->pluck('id') as $id) {
    $check = $verifier->verify((int) $id);
    fwrite($out, json_encode($check, JSON_UNESCAPED_SLASHES)."\n");
    $report['checked_contents']++;
    $report['checked_chunks'] += $check['chunks'] ?? 0;
    $report['source_bytes'] += $check['source_bytes'] ?? 0;
    if (! $check['valid']) {
        $report['errors'][] = $check;
    }
    if ($report['checked_contents'] % 1000 === 0) {
        echo json_encode(['verified_contents' => $report['checked_contents'], 'errors' => count($report['errors'])])."\n";
        usleep(100000);
    }
}
fclose($out);
$manifest = json_decode(file_get_contents($root.'/docs/ai/snapshot_manifest.json'), true, flags: JSON_THROW_ON_ERROR);
$report['source_preservation'] = [];
foreach ($manifest['tables'] as $table => $expected) {
    $h = hash_init('sha256');
    $count = 0;
    $last = 0;
    do {
        $rows = $db->table($table)->where('id', '>', $last)->orderBy('id')->limit(100)->select('id')->selectRaw('row_to_json('.$table.')::text AS row')->get();
        foreach ($rows as $row) {
            hash_update($h, $row->row."\n");
            $count++;
            $last = $row->id;
        }
    } while ($rows->count() === 100);
    $actual = ['rows' => $count, 'sha256' => hash_final($h)];
    $report['source_preservation'][$table] = ['unchanged' => $actual === $expected, ...$actual];
    if ($actual !== $expected) {
        $report['errors'][] = ['table' => $table, 'reason' => 'source_table_changed'];
    }
}
$current = $service->currentChunks();
$report['quality'] = (clone $current)->selectRaw('k.quality_status,count(*) AS chunks')->groupBy('k.quality_status')->get()->all();
$report['lengths'] = (clone $current)->selectRaw('min(char_length(k.content)) AS minimum,max(char_length(k.content)) AS maximum,avg(char_length(k.content)) AS average,sum(char_length(k.content)) AS total_characters')->first();
$report['under_80_chars'] = (clone $current)->whereRaw('char_length(k.content)<80')->count();
$report['missing_source_urls'] = (clone $current)->whereNull('k.source_url')->count();
$report['table_chunks'] = (clone $current)->whereRaw("(k.metadata->>'contains_table')::boolean")->count();
$report['review_reasons'] = $db->table('document_chunks as k')->join('document_chunk_sets as s', 's.id', '=', 'k.chunk_set_id')
    ->crossJoin(DB::raw("LATERAL jsonb_array_elements_text(k.metadata->'review_reasons') AS reason"))
    ->where('s.is_current', true)->where('s.profile_hash', $service->profile((int) config('ai.chunk_size')))
    ->selectRaw('reason, count(*) AS chunks')->groupBy('reason')->orderByDesc('chunks')->get()->all();
$report['over_target'] = (clone $current)->whereRaw('char_length(k.content)>?', [config('ai.chunk_size')])->count();
$report['over_maximum'] = (clone $current)->whereRaw('char_length(k.content)>?', [config('ai.max_chunk_size')])->count();
$report['oversize_examples'] = (clone $current)->whereRaw('char_length(k.content)>?', [config('ai.chunk_size')])->select('k.id', 'k.document_id', 'k.document_content_id', 'k.language', 'k.quality_status', 'd.title', 'k.source_url')->selectRaw('char_length(k.content) AS characters')->orderByRaw('char_length(k.content) DESC')->limit(30)->get()->all();
$report['languages'] = (clone $current)->selectRaw('k.language,count(*) AS chunks,count(DISTINCT k.document_content_id) AS contents')->groupBy('k.language')->get()->all();
$report['categories'] = (clone $current)->selectRaw('d.document_type,count(*) AS chunks,count(DISTINCT d.id) AS documents')->groupBy('d.document_type')->get()->all();
$report['legal_statuses'] = (clone $current)->selectRaw('d.status,count(*) AS chunks')->groupBy('d.status')->get()->all();
$report['section_types'] = (clone $current)->selectRaw('k.section_type,count(*) AS chunks')->groupBy('k.section_type')->get()->all();
$report['fallback_contents'] = (clone $current)->whereRaw("(k.metadata->>'fallback')::boolean")->distinct()->count('k.document_content_id');
$report['review_contents'] = (clone $current)->where('k.quality_status', 'needs_review')->distinct()->count('k.document_content_id');
$report['source_quality_issues'] = $db->select('SELECT issue,count(*) AS contents FROM document_contents c CROSS JOIN LATERAL jsonb_array_elements_text(c.quality_issues) AS issue GROUP BY issue ORDER BY count(*) DESC LIMIT 30');
$report['duplicate_chunk_identity_groups'] = $db->table('document_chunks')->select('chunk_set_id', 'chunk_index')->groupBy('chunk_set_id', 'chunk_index')->havingRaw('count(*)>1')->count();
$report['multiple_current_contents'] = $db->table('document_chunk_sets')->where('is_current', true)->select('document_content_id')->groupBy('document_content_id')->havingRaw('count(*)>1')->count();
$report['repeated_hash_groups'] = (clone $current)->select('k.document_content_id', 'k.content_hash')->groupBy('k.document_content_id', 'k.content_hash')->havingRaw('count(*)>1')->get()->count();
$report['repeated_hash_note'] = 'Identical text can legitimately recur at different source offsets; exact reassembly validates that it was not introduced by chunking.';
$before = $db->table('document_chunks')->selectRaw("count(*) AS count,max(id) AS max_id,md5(string_agg(id::text || ':' || content_hash, ',' ORDER BY id)) AS digest")->first();
$exit = Artisan::call('legal:chunk-documents', ['--only-pending' => true, '--content-limit' => 100]);
$after = $db->table('document_chunks')->selectRaw("count(*) AS count,max(id) AS max_id,md5(string_agg(id::text || ':' || content_hash, ',' ORDER BY id)) AS digest")->first();
$report['repeat_command'] = ['exit_code' => $exit, 'output' => trim(Artisan::output()), 'unchanged' => $before == $after];
$baseline = json_decode(file_get_contents($dir.'/before.json'), true);
$h = hash_init('sha256');
foreach ($db->table('document_chunks')->where('chunking_version', 'legal-v1')->orderBy('id')->cursor() as $row) {
    hash_update($h, json_encode($row, JSON_THROW_ON_ERROR)."\n");
}
$report['v1_chunk_rows_unchanged'] = hash_final($h) === $baseline['document_chunks_sha256'];
$phases = json_decode(file_get_contents($dir.'/phases.json'), true, flags: JSON_THROW_ON_ERROR);
$selected = [];
foreach ($phases as $phase) {
    foreach ($phase['batches'] as $batch) {
        $selected = array_merge($selected, $batch['content_ids']);
    }
}
$report['selected_content_count'] = count($selected);
$report['distinct_selected_content_count'] = count(array_unique($selected));
if (count($selected) !== $status['contents'] || count(array_unique($selected)) !== $status['contents']) {
    $report['errors'][] = 'phase_selection_coverage_or_duplicate';
}
$report['skipped_contents'] = 0;
$report['skipped_reason'] = 'All authoritative stored source representations were eligible; pending_candidate alternatives were not treated as authoritative sources.';
$report['human_review_performed'] = false;
$report['passed'] = $report['errors'] === [] && $report['repeat_command']['unchanged'] && $report['v1_chunk_rows_unchanged'] && $report['duplicate_chunk_identity_groups'] === 0 && $report['multiple_current_contents'] === 0;
file_put_contents($dir.'/final_validation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['passed' => $report['passed'], 'checked_contents' => $report['checked_contents'], 'checked_chunks' => $report['checked_chunks'], 'errors' => count($report['errors'])])."\n";
exit($report['passed'] ? 0 : 1);
