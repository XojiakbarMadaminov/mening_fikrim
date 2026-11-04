<?php

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\ChunkDocumentService;
use Illuminate\Contracts\Console\Kernel;
use Tests\Fixtures\LegalV1\DocumentStructureParser;
use Tests\Fixtures\LegalV1\LegalDocumentChunker;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$guard = app(AiDatabaseGuard::class);
$db = $guard->connection();
$service = app(ChunkDocumentService::class);
$v1 = new LegalDocumentChunker(new DocumentStructureParser);
$v2 = app(App\Services\Legal\LegalDocumentChunker::class);
$ids = $db->table('document_chunk_sets')->where('chunking_version', 'legal-v1')->pluck('document_content_id')->all();
foreach (['Закон', 'Указ', 'Положение'] as $type) {
    $ids = array_merge($ids, $service->sources()->where('d.document_type', $type)->orderBy('c.id')->limit(2)->pluck('c.id')->all());
}
$report = ['at' => now()->toIso8601String(), 'versions' => ['legal-v1', 'legal-v2'], 'source_requests' => 0, 'samples' => [], 'errors' => []];
foreach (array_unique($ids) as $id) {
    $source = $service->sources()->where('c.id', $id)->first();
    $options = ['quality_status' => $source->quality_status, 'quality_issues' => json_decode($source->quality_issues, true), 'extraction_metadata' => json_decode($source->extraction_metadata, true)];
    $old = $v1->chunk($source->content, 4000, $options);
    $new = $v2->chunk($source->content, 4000, $options, config('ai'));
    $coverage = implode('', array_column($old, 'content')) === $source->content && implode('', array_column($new, 'content')) === $source->content;
    if (! $coverage || ($source->quality_status === 'needs_review' && array_any($new, fn ($c) => $c['quality_status'] !== 'needs_review'))) {
        $report['errors'][] = ['content_id' => $id, 'reason' => 'coverage_or_quality_promotion'];
    }
    $report['samples'][] = ['content_id' => $id, 'document_id' => $source->document_id, 'language' => $source->language,
        'source_quality' => $source->quality_status, 'source_bytes' => strlen($source->content), 'v1_chunks' => count($old), 'v2_chunks' => count($new),
        'v1_needs_review' => count(array_filter($old, fn ($c) => $c['quality_status'] === 'needs_review')), 'v2_needs_review' => count(array_filter($new, fn ($c) => $c['quality_status'] === 'needs_review')),
        'v2_named_parts' => count(array_filter($new, fn ($c) => $c['section_type'] === 'document_part')), 'exact_coverage' => $coverage];
}
$hash = hash_init('sha256');
foreach ($db->table('document_chunks')->where('chunking_version', 'legal-v1')->orderBy('id')->cursor() as $row) {
    hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
}
$baseline = json_decode(file_get_contents(dirname(__DIR__).'/docs/ai_p2/before.json'), true);
$report['v1_chunk_rows_unchanged'] = hash_final($hash) === $baseline['document_chunks_sha256'];
$report['approved_for_pilot'] = $report['errors'] === [] && $report['v1_chunk_rows_unchanged'];
file_put_contents(dirname(__DIR__).'/docs/ai_p2/version_comparison.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['samples' => count($report['samples']), 'approved_for_pilot' => $report['approved_for_pilot'], 'errors' => $report['errors']])."\n";
exit($report['approved_for_pilot'] ? 0 : 1);
