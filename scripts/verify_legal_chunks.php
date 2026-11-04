<?php

require dirname(__DIR__).'/vendor/autoload.php';

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\ChunkDocumentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
throw new RuntimeException('P1 acceptance evidence is frozen. Use scripts/ai_p2_validate.php for the current corpus.');
if (app()->runningUnitTests() || $db->getDatabaseName() !== 'mening_fikrim_ai_dev') {
    throw new RuntimeException('Acceptance is only for the explicitly isolated AI DEV copy.');
}
$report = ['checked_at' => now()->toIso8601String(), 'database' => $db->getDatabaseName(), 'source_requests' => 0, 'documents' => [], 'errors' => []];
$ids = [];
foreach (['uz-Latn', 'uz-Cyrl', 'ru'] as $language) {
    foreach (['html', 'pdf', 'ocr'] as $format) {
        $candidates = $db->table('document_contents')->where('language', $language)
            ->whereRaw("extraction_metadata->'quality'->>'format' = ?", [$format])->orderBy('id')->limit(3)->pluck('document_id')->all();
        $ids = array_merge($ids, $candidates);
    }
}
$ids = array_values(array_unique($ids));
foreach ($db->table('documents')->orderBy('id')->limit(100)->pluck('id') as $id) {
    if (count($ids) >= 15) {
        break;
    }
    if (! in_array($id, $ids, true)) {
        $ids[] = $id;
    }
}
$ids = array_slice($ids, 0, 15);
$beforeCount = $db->table('document_chunks')->count();
if (Artisan::call('legal:chunk-documents', ['--document-id' => $ids, '--limit' => 15, '--dry-run' => true]) !== 0 || $db->table('document_chunks')->count() !== $beforeCount) {
    throw new RuntimeException('Acceptance dry run failed or wrote chunks.');
}
if (Artisan::call('legal:chunk-documents', ['--document-id' => $ids, '--limit' => 15]) !== 0) {
    throw new RuntimeException('Acceptance processing failed: '.Artisan::output());
}
$chunkDigest = function () use ($db): string {
    $hash = hash_init('sha256');
    foreach ($db->table('document_chunks')->orderBy('id')->cursor() as $row) {
        hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
    }

    return hash_final($hash);
};
$before = $chunkDigest();
if (Artisan::call('legal:chunk-documents', ['--document-id' => $ids, '--limit' => 15]) !== 0 || $chunkDigest() !== $before) {
    throw new RuntimeException('Repeated command changed unchanged chunk rows.');
}
$report['repeat_command_unchanged'] = true;
$service = app(ChunkDocumentService::class);
$examples = "# Real snapshot chunk examples\n\nGenerated locally; no source requests. Offsets are UTF-8 bytes in unchanged stored Markdown.\n";
foreach ($ids as $id) {
    $document = $db->table('documents')->where('id', $id)->first();
    $entry = ['document_id' => $id, 'title' => $document->title, 'languages' => [], 'chunks' => 0];
    foreach ($service->sources()->where('c.document_id', $id)->orderBy('c.id')->cursor() as $source) {
        $chunks = $db->table('document_chunks')->where('document_content_id', $source->id)->orderBy('chunk_index')->get();
        $text = '';
        $offset = 0;
        foreach ($chunks as $index => $chunk) {
            $metadata = json_decode($chunk->metadata, true, flags: JSON_THROW_ON_ERROR);
            if ($chunk->chunk_index !== $index || $metadata['start_byte'] !== $offset || $metadata['end_byte'] !== $offset + strlen($chunk->content)
                || hash('sha256', $chunk->content) !== $chunk->content_hash || $chunk->source_checksum !== $source->content_hash || $chunk->document_id !== $source->document_id || $chunk->language !== $source->language) {
                $report['errors'][] = ['content_id' => $source->id, 'chunk_id' => $chunk->id, 'reason' => 'reference_or_offset_or_hash'];
            }
            $text .= $chunk->content;
            $offset += strlen($chunk->content);
        }
        if ($text !== $source->content) {
            $report['errors'][] = ['content_id' => $source->id, 'reason' => 'text_coverage'];
        }
        $extraction = json_decode($source->extraction_metadata, true, flags: JSON_THROW_ON_ERROR);
        $entry['languages'][] = [
            'content_id' => $source->id, 'language' => $source->language, 'format' => $extraction['quality']['format'] ?? 'unknown',
            'source_quality' => $source->quality_status, 'source_bytes' => strlen($source->content), 'chunks' => $chunks->count(),
            'articles' => $chunks->where('section_type', 'article')->pluck('section_number')->unique()->count(),
            'quality_counts' => $chunks->countBy('quality_status')->all(), 'oversize_chunks' => $chunks->filter(fn ($chunk) => json_decode($chunk->metadata, true)['oversize'])->count(), 'coverage_verified' => $text === $source->content,
        ];
        $entry['chunks'] += $chunks->count();
        if (in_array($id, [1, 2, 3], true)) {
            $example = $chunks->firstWhere('section_type', 'article') ?? $chunks->first();
            if ($example) {
                $meta = json_decode($example->metadata, true);
                $examples .= "\n## Document $id / content $source->id / $source->language\n\n";
                $examples .= "Type: $example->section_type; number: ".($example->section_number ?? 'unknown').'; chapter: '.($meta['context']['chapter']['number'] ?? 'unknown')."; chunk index: $example->chunk_index; bytes: {$meta['start_byte']}..{$meta['end_byte']}; quality: $example->quality_status.\n\n";
                $examples .= 'Source: '.$example->source_url."\n\n```text\n".mb_substr($example->content, 0, 450)."\n```\n\nExcerpt only; full content remains in the AI database.\n";
            }
        }
    }
    $report['documents'][] = $entry;
}
$report['processed_documents'] = count($ids);
$report['processed_contents'] = $db->table('document_chunk_sets')->count();
$report['total_chunks'] = $db->table('document_chunks')->count();
$report['queue_jobs'] = $db->table('ai_jobs')->count();
$report['quality_counts'] = $db->table('document_chunks')->selectRaw('quality_status, count(*) as count')->groupBy('quality_status')->pluck('count', 'quality_status')->all();
$report['source_corpus_unchanged'] = true;
$manifest = json_decode(file_get_contents(dirname(__DIR__).'/docs/ai/snapshot_manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest['tables'] as $table => $expected) {
    $hash = hash_init('sha256');
    $count = 0;
    foreach ($db->select("SELECT row_to_json(t)::text AS row FROM (SELECT * FROM $table ORDER BY id) t") as $row) {
        hash_update($hash, $row->row."\n");
        $count++;
    }
    if ($expected !== ['rows' => $count, 'sha256' => hash_final($hash)]) {
        $report['source_corpus_unchanged'] = false;
        $report['errors'][] = ['table' => $table, 'reason' => 'snapshot_source_changed'];
    }
}
file_put_contents(dirname(__DIR__).'/docs/ai/verification.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
file_put_contents(dirname(__DIR__).'/docs/ai/CHUNK_EXAMPLES.md', $examples);
echo json_encode(array_diff_key($report, ['documents' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($report['errors'] === [] ? 0 : 1);
