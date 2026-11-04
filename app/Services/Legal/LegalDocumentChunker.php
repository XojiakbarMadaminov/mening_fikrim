<?php

namespace App\Services\Legal;

use InvalidArgumentException;

class LegalDocumentChunker
{
    public function __construct(private DocumentStructureParser $parser) {}

    public function chunk(string $text, int $size = 4000, array $source = [], array $policy = []): array
    {
        if ($size < 200 || $size > 32000) {
            throw new InvalidArgumentException('Chunk size must be 200..32000 Unicode characters.');
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Source content is not valid UTF-8.');
        }
        if (trim($text) === '') {
            return [];
        }
        $review = ($source['quality_status'] ?? 'verified') !== 'verified';
        $quality = $source['extraction_metadata']['quality'] ?? [];
        $hasOcr = ($quality['format'] ?? '') === 'ocr' || ($quality['ocr'] ?? false) === true || ! empty($quality['ocr_pages'])
            || array_any($quality['page_stats'] ?? [], fn ($page) => ($page['ocr'] ?? false) === true);
        $uncertain = str_contains($text, '�') || ($review && $hasOcr);
        $sections = $this->parser->parse($text, ! $uncertain && (bool) ($policy['structure_splitting'] ?? true));
        $sourceLength = mb_strlen(trim($text));
        $structured = count(array_filter($sections, fn ($section) => in_array($section['type'], ['article', 'chapter', 'section', 'point'], true))) > 0;
        $chunks = [];
        foreach ($sections as $section) {
            $body = substr($text, $section['start'], $section['end'] - $section['start']);
            $boundaries = array_map(fn ($entry) => $entry['offset'] - $section['start'], $section['boundaries']);
            foreach ($this->split($body, $size, $boundaries) as [$start, $end]) {
                $content = substr($body, $start, $end - $start);
                $oversize = mb_strlen($content) > $size;
                $reasons = [];
                if ($review) {
                    $reasons[] = 'source_needs_review';
                }
                if ($uncertain) {
                    $reasons[] = 'structure_uncertain';
                }
                if (! $structured) {
                    $reasons[] = 'structure_unrecognized';
                }
                if ($sourceLength < 80) {
                    $reasons[] = 'short_source';
                }
                if ($oversize) {
                    $reasons[] = 'oversize_atomic';
                }
                if (mb_strlen($content) > (int) ($policy['max_chunk_size'] ?? 8000)) {
                    $reasons[] = 'maximum_length_exceeded';
                }
                $chunks[] = [
                    'section_type' => $section['type'], 'section_number' => $section['number'], 'section_title' => $section['title'],
                    'content' => $content, 'content_hash' => hash('sha256', $content),
                    'quality_status' => $reasons !== [] ? 'needs_review' : 'structured',
                    'metadata' => [
                        'start_byte' => $section['start'] + $start, 'end_byte' => $section['start'] + $end,
                        'context' => $section['context'], 'subdivision_context' => $this->subdivisions($section['boundaries'], $section['start'] + $start), 'boundaries' => array_values(array_filter($section['boundaries'], fn ($boundary) => $boundary['offset'] >= $section['start'] + $start && $boundary['offset'] < $section['start'] + $end)),
                        'characters' => mb_strlen($content), 'estimated_tokens' => (int) ceil(mb_strlen($content) / 4), 'token_count_method' => 'heuristic_chars_div_4',
                        'review_reasons' => $reasons, 'fallback' => ! $structured || $uncertain, 'maximum_characters' => (int) ($policy['max_chunk_size'] ?? 8000),
                        'contains_table' => (bool) preg_match('/^\s*\||\[T\d+:R\d+C\d+/m', $content), 'overlap_bytes' => 0, 'oversize' => $oversize, 'structure_uncertain' => (bool) $uncertain, 'source_quality' => $source['quality_status'] ?? 'unknown',
                        'source_quality_issues' => $source['quality_issues'] ?? [], 'source_format' => $source['extraction_metadata']['quality']['format'] ?? 'unknown',
                    ],
                ];
            }
        }
        if (implode('', array_column($chunks, 'content')) !== $text) {
            throw new \LogicException('Chunk coverage check failed; refusing text loss.');
        }

        return $chunks;
    }

    private function subdivisions(array $boundaries, int $offset): array
    {
        $context = ['part' => null, 'point' => null, 'subpoint' => null];
        foreach ($boundaries as $boundary) {
            if ($boundary['offset'] > $offset) {
                break;
            }
            $context[$boundary['type']] = $boundary;
            if ($boundary['type'] === 'part') {
                $context['point'] = $context['subpoint'] = null;
            } elseif ($boundary['type'] === 'point') {
                $context['subpoint'] = null;
            }
        }

        return $context;
    }

    /** Prefer legal subdivisions and blank lines, then sentences. Atomic tables/long sentences stay intact. */
    private function split(string $body, int $size, array $boundaries): array
    {
        if (mb_strlen($body) <= $size) {
            return [[0, strlen($body)]];
        }
        preg_match_all('/\R[\t ]*\R/u', $body, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [$separator, $offset]) {
            $boundaries[] = $offset + strlen($separator);
        }
        $boundaries[] = 0;
        $boundaries[] = strlen($body);
        $boundaries = array_values(array_unique($boundaries));
        sort($boundaries);
        $atomic = [];
        for ($i = 0; $i < count($boundaries) - 1; $i++) {
            $start = $boundaries[$i];
            $end = $boundaries[$i + 1];
            $part = substr($body, $start, $end - $start);
            if (mb_strlen($part) > $size && ! preg_match('/^\s*\||\[T\d+:R\d+C\d+/m', $part)) {
                preg_match_all('/[.!?;](?=\s+)/u', $part, $sentences, PREG_OFFSET_CAPTURE);
                foreach ($sentences[0] as [$punctuation, $offset]) {
                    $cut = $boundaries[$i] + $offset + strlen($punctuation);
                    if ($cut > $start) {
                        $atomic[] = [$start, $cut];
                        $start = $cut;
                    }
                }
            }
            if ($end > $start) {
                $atomic[] = [$start, $end];
            }
        }
        $ranges = [];
        $start = 0;
        $end = 0;
        foreach ($atomic as [$a, $b]) {
            if ($end > $start && mb_strlen(substr($body, $start, $b - $start)) > $size) {
                $ranges[] = [$start, $end];
                $start = $a;
            }
            $end = $b;
        }
        if ($end > $start) {
            $ranges[] = [$start, $end];
        }

        return $ranges;
    }
}
