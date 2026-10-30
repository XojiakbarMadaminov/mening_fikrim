<?php

use App\Models\Document;
use App\Models\SourceArchiveObservation;

require __DIR__.'/runtime.php';
$baseline = p6Read('database_baseline');
$baselineMax = max(array_keys($baseline['record_hashes']['documents']));
$candidates = [];
foreach (Document::with('contents')->orderBy('id')->get() as $document) {
    foreach ($document->contents as $content) {
        $format = $content->extraction_metadata['quality']['format'] ?? 'unknown';
        $score = ($format === 'ocr' ? 100 : ($format === 'pdf' ? 80 : 0))
            + ($document->id > $baselineMax ? 30 : 0) + ($content->quality_status === 'needs_review' ? 20 : 0)
            + min(10, (int) (mb_strlen($content->content) / 10000));
        $candidates[] = ['score' => $score, 'document' => $document, 'content' => $content, 'format' => $format];
    }
}
usort($candidates, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['document']->id <=> $b['document']->id) ?: strcmp($a['content']->language, $b['content']->language));
$chosen = [];
$select = function (callable $filter, int $limit) use (&$chosen, $candidates, $baselineMax): void {
    if (count($chosen) >= 30) {
        return;
    }
    $added = 0;
    foreach ($candidates as $candidate) {
        $document = $candidate['document'];
        if (isset($chosen[$document->id]) || ! $filter($candidate)) {
            continue;
        }
        $content = $candidate['content'];
        $observation = SourceArchiveObservation::with('blob')->where('document_id', $document->id)
            ->where('language', $content->language)->orderByDesc('id')->first();
        $chosen[$document->id] = ['document_id' => $document->id, 'canonical_anchor' => $document->external_id,
            'cohort' => $document->id > $baselineMax ? 'P6_new' : 'historical_P0_P5_1', 'source_url' => $content->extraction_metadata['source_url'] ?? $document->source_url,
            'title' => $content->title, 'legal_status' => $document->status->value, 'content_id' => $content->id,
            'language' => $content->language, 'format' => $candidate['format'], 'content_sha256' => $content->content_hash,
            'characters' => mb_strlen($content->content), 'quality_status' => $content->quality_status, 'quality_issues' => $content->quality_issues,
            'archive_status' => $observation?->archive_status ?? 'archive_missing',
            'original_source_url' => $observation?->source_url,
            'original_file' => $observation?->blob ? ['disk' => $observation->blob->storage_disk, 'path' => $observation->blob->storage_path, 'sha256' => $observation->blob->sha256] : null,
            'excerpt_start' => mb_substr($content->content, 0, 1200), 'excerpt_end' => mb_substr($content->content, -800),
            'human_review_status' => 'pending', 'reviewer' => null, 'reviewed_at' => null,
            'human_observations' => ['reading_order' => null, 'missing_text' => null, 'tables' => null, 'language' => null, 'ocr_accuracy' => null, 'notes' => null]];
        $added++;
        if (count($chosen) >= 30 || $added === $limit) {
            break;
        }
    }
};
foreach (['active', 'pending', 'inactive'] as $status) {
    $select(fn ($candidate) => $candidate['document']->status->value === $status, 1);
}
$select(fn ($candidate) => in_array($candidate['format'], ['pdf', 'ocr'], true), 8);
foreach (['uz-Latn', 'uz-Cyrl', 'ru', 'en'] as $language) {
    $select(fn ($candidate) => $candidate['content']->language === $language, 1);
}
foreach ([['needs_review', true, 6], ['verified', true, 6], ['needs_review', false, 4], ['verified', false, 4]] as [$quality, $new, $limit]) {
    if (count($chosen) < 30) {
        $select(fn ($candidate) => $candidate['content']->quality_status === $quality && ($candidate['document']->id > $baselineMax) === $new, $limit);
    }
}
if (count($chosen) < 30) {
    $select(fn () => true, 30 - count($chosen));
}
p6Save('human_qa_manifest', ['at' => now()->toAtomString(), 'method' => 'Distinct canonicals: available legal states, up to8PDF/OCR, language strata, new verified/review and historical verified/review; larger content preferred within strata. Stable IDs break ties. Absent strata are not fabricated.',
    'human_review_performed' => false, 'count' => count($chosen), 'documents' => array_values($chosen)]);
echo 'Prepared '.count($chosen)." distinct documents; human review remains pending.\n";
