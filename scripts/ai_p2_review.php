<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\ChunkDocumentService;
use Illuminate\Contracts\Console\Kernel;

$root = dirname(__DIR__);
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
$service = app(ChunkDocumentService::class);
$db->beginTransaction();
$db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
$groups = [];
foreach (['uz-Latn', 'uz-Cyrl', 'ru'] as $language) {
    $groups[$language] = $service->sources()->where('c.language', $language);
}
$groups['law'] = $service->sources()->where('d.document_type', 'like', 'Закон%');
$groups['decision'] = $service->sources()->whereIn('d.document_type', ['Постановление', 'Решение']);
$groups['decree'] = $service->sources()->where('d.document_type', 'Указ');
$groups['regulation'] = $service->sources()->where(fn ($q) => $q->where('d.document_type', 'Положение')->orWhere('c.title', 'ilike', '%nizom%')->orWhere('c.title', 'ilike', '%низом%')->orWhere('c.title', 'ilike', '%положени%'));
$groups['code'] = $service->sources()->where('d.document_type', 'Кодекс');
foreach (['pdf', 'ocr'] as $format) {
    $groups[$format] = $service->sources()->whereRaw("c.extraction_metadata->'quality'->>'format' = ?", [$format]);
}
$groups['needs_review'] = $service->sources()->whereIn('c.id', $service->currentChunks()->where('k.quality_status', 'needs_review')->select('k.document_content_id'));
$groups['table'] = $service->sources()->whereIn('c.id', $service->currentChunks()->whereRaw("(k.metadata->>'contains_table')::boolean")->select('k.document_content_id'));
$groups['very_large'] = $service->sources()->whereRaw('char_length(c.content)>1000000');
$report = ['at' => now()->toIso8601String(), 'review_kind' => 'source_and_chunk_boundary_packet', 'human_review_performed' => false, 'manual_verified_rows_created' => 0, 'groups' => [], 'samples' => []];
$selected = [];
foreach ($groups as $group => $query) {
    $docs = [];
    $ids = [];
    foreach ($query->select('c.id', 'c.document_id')->orderBy('c.id')->limit(50)->get() as $source) {
        if (in_array($source->document_id, $docs, true)) {
            continue;
        }
        $docs[] = $source->document_id;
        $ids[] = $source->id;
        $selected[$source->id] = true;
        if (count($ids) >= 2) {
            break;
        }
    }
    $report['groups'][$group] = ['content_ids' => $ids, 'distinct_documents' => count($docs), 'unavailable' => $ids === []];
}
foreach (array_keys($selected) as $id) {
    $source = $service->sources()->where('c.id', $id)->first();
    $set = $db->table('document_chunk_sets')->where('document_content_id', $id)->where('is_current', true)->where('profile_hash', $service->profile((int) config('ai.chunk_size')))->first();
    if (! $set) {
        throw new RuntimeException('Review source is not current: '.$id);
    }
    $base = $db->table('document_chunks')->where('chunk_set_id', $set->id)->select('id', 'chunk_index', 'section_type', 'section_number', 'quality_status', 'source_url', 'metadata')->selectRaw('left(content,300) AS content_excerpt');
    $candidates = [(clone $base)->orderBy('chunk_index')->first(), (clone $base)->orderBy('chunk_index')->offset((int) floor($set->chunk_count / 2))->first(), (clone $base)->orderByDesc('chunk_index')->first(),
        (clone $base)->where('section_type', 'article')->orderBy('chunk_index')->first(), (clone $base)->whereRaw("(metadata->>'contains_table')::boolean")->orderBy('chunk_index')->first(),
        (clone $base)->whereRaw("(metadata->>'oversize')::boolean")->orderBy('chunk_index')->first()];
    $boundaries = [];
    $seen = [];
    foreach ($candidates as $chunk) {
        if (! $chunk || isset($seen[$chunk->id])) {
            continue;
        }$seen[$chunk->id] = true;
        $m = json_decode($chunk->metadata, true);
        $window = max(0, $m['start_byte'] - 120);
        while ($window > 0 && (ord($source->content[$window]) & 0xC0) === 0x80) {
            $window--;
        }
        $boundaries[] = ['chunk_id' => $chunk->id, 'chunk_index' => $chunk->chunk_index, 'section_type' => $chunk->section_type, 'section_number' => $chunk->section_number,
            'quality_status' => $chunk->quality_status, 'start_byte' => $m['start_byte'], 'end_byte' => $m['end_byte'], 'context' => $m['context'],
            'review_reasons' => $m['review_reasons'] ?? [], 'content_excerpt' => $chunk->content_excerpt, 'source_window_start_byte' => $window, 'source_window' => mb_strcut($source->content, $window, 700, 'UTF-8')];
    }
    $report['samples'][] = ['content_id' => $id, 'document_id' => $source->document_id, 'title' => $source->title, 'language' => $source->language, 'source_quality' => $source->quality_status,
        'format' => json_decode($source->extraction_metadata, true)['quality']['format'] ?? 'unknown', 'source_checksum' => $source->content_hash, 'source_bytes' => strlen($source->content), 'chunks' => $set->chunk_count,
        'source_url' => json_decode($source->extraction_metadata, true)['source_url'] ?? $source->source_url, 'boundaries' => $boundaries];
}
$db->rollBack();
file_put_contents($root.'/docs/ai_p2/review_packet.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
$md = "# Source and chunk boundary review packet\n\nThis is an automated packet for operator review. No human/manual_verified status is asserted. Full exact reconstruction is checked independently in final_validation.json. Excerpts below are intentionally short.\n\n";
foreach ($report['groups'] as $name => $group) {
    $md .= '- '.$name.': contents '.implode(', ', $group['content_ids']).'; '.$group['distinct_documents']." distinct documents.\n";
}
foreach ($report['samples'] as $sample) {
    $md .= "\n## Content {$sample['content_id']} / document {$sample['document_id']} / {$sample['language']}\n\n{$sample['title']}\n\nFormat: {$sample['format']}; source quality: {$sample['source_quality']}; chunks: {$sample['chunks']}; source bytes: {$sample['source_bytes']}.\n\nSource: ".($sample['source_url'] ?? 'unavailable')."\n";
    foreach (array_slice($sample['boundaries'], 0, 3) as $b) {
        $md .= "\nChunk {$b['chunk_index']} (ID {$b['chunk_id']}): {$b['section_type']} ".($b['section_number'] ?? 'unknown').", bytes [{$b['start_byte']},{$b['end_byte']}), {$b['quality_status']}.\n\nOriginal source window (starts at byte {$b['source_window_start_byte']}):\n\n```text\n".mb_substr($b['source_window'], 0, 380)."\n```\n\nChunk excerpt:\n\n```text\n".$b['content_excerpt']."\n```\n";
    }
}
file_put_contents($root.'/docs/ai_p2/REVIEW_PACKET.md', $md);
echo json_encode(['samples' => count($report['samples']), 'groups' => count($report['groups']), 'human_review_performed' => false])."\n";
