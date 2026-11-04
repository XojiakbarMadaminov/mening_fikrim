<?php

namespace App\Services\Chunking;

class ChunkQualityAnalyzer
{
    public function analyze(string $body, array $source, array $hints): array
    {
        $issues = $source['quality_issues'] ?? [];
        $quality = $source['extraction_metadata']['quality'] ?? [];
        $format = $quality['format'] ?? $source['format'] ?? 'unknown';
        $reasons = [];
        if (($source['quality_status'] ?? 'needs_review') !== 'verified') {
            $reasons[] = 'source_extraction_review';
        }
        if ($format === 'ocr' || ! empty($quality['ocr_pages'])) {
            $reasons[] = 'ocr_uncertified';
        }
        if ($hints['table'] ?? false) {
            $reasons[] = 'table_layout_review';
        }
        if ($hints['semantic_fragment'] ?? false) {
            $reasons[] = 'semantic_boundary_uncertain';
        }
        if ($hints['fallback'] ?? false) {
            $reasons[] = 'structure_unrecognized';
        }
        if (mb_strlen($body) < 80) {
            $reasons[] = 'short_body';
        }
        $damaged = str_contains($body, '�') || array_any($issues, fn ($issue) => (bool) preg_match('/loss|missing|incomplete|unextracted|low_ocr|empty|corrupt/ui', $issue));
        if ($damaged) {
            $reasons[] = 'potential_text_damage';
        }
        if (trim($body) === '') {
            $reasons[] = 'no_lexical_text';
        }
        $suitability = trim($body) === '' ? 'blocked' : (($damaged || ($source['quality_status'] ?? '') !== 'verified' || ($hints['semantic_fragment'] ?? false) || ($hints['table'] ?? false)) ? 'review' : 'eligible');

        return ['source_extraction' => ['status' => $source['quality_status'] ?? 'unknown', 'format' => $format, 'issues' => $issues],
            'structure' => ($hints['fallback'] ?? false) ? 'unrecognized' : 'observed', 'retrieval_suitability' => $suitability,
            'human_review' => 'not_reviewed', 'reasons' => array_values(array_unique($reasons))];
    }
}
