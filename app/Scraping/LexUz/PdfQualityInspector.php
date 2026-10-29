<?php

namespace App\Scraping\LexUz;

use DOMElement;

class PdfQualityInspector
{
    public function page(DOMElement $page): array
    {
        $width = (float) $page->getAttribute('width');
        $height = (float) $page->getAttribute('height');
        $left = [];
        $right = [];
        $outside = 0;
        foreach ($page->getElementsByTagName('text') as $text) {
            $x = (float) $text->getAttribute('left');
            $y = (float) $text->getAttribute('top');
            $w = (float) $text->getAttribute('width');
            $h = (float) $text->getAttribute('height');
            if ($x < -2 || $y < -2 || $x + $w > $width + 2 || $y + $h > $height + 2) {
                $outside++;
            }
            if ($y > .08 * $height && $y < .92 * $height && trim($text->textContent) !== '') {
                if ($x + $w < .53 * $width) {
                    $left[] = [$y, $y + $h];
                } elseif ($x > .47 * $width) {
                    $right[] = [$y, $y + $h];
                }
            }
        }
        $overlaps = 0;
        foreach ($left as [$top, $bottom]) {
            foreach ($right as [$otherTop, $otherBottom]) {
                $overlaps += $top < $otherBottom && $otherTop < $bottom ? 1 : 0;
            }
        }

        return ['multi_column_suspected' => count($left) >= 3 && count($right) >= 3 && $overlaps >= 3,
            'out_of_page_text_boxes' => $outside, 'reading_order_verified' => false,
            'missing_or_merged_lines_verified' => false];
    }

    public function compareGold(string $reference, string $observed): array
    {
        $normalize = fn ($text) => trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($text))));
        $a = $normalize($reference);
        $b = $normalize($observed);
        if (mb_strlen($a) > 2000 || mb_strlen($b) > 4000 || $a === '') {
            throw new SourceReviewException('Gold comparison requires a bounded nonempty manual excerpt.');
        }
        $characters = $this->distance(preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY), preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY));
        $words = $this->distance(explode(' ', $a), $b === '' ? [] : explode(' ', $b));

        return ['normalization' => 'Unicode lowercase; punctuation to spaces; compact whitespace', 'reference_chars' => mb_strlen($a),
            'character_errors' => $characters, 'CER' => (float) $characters / mb_strlen($a), 'word_errors' => $words, 'WER' => (float) $words / count(explode(' ', $a)),
            'document_certified' => false, 'scope' => 'excerpt_only'];
    }

    /** Bounded review coordinates; total count and sampling are explicit. */
    public function ocrRegions(string $tsv): array
    {
        $regions = [];
        $total = 0;
        $lines = [];
        $raster = null;
        foreach (preg_split('/\R/u', $tsv) as $index => $line) {
            $row = explode("\t", $line, 12);
            if ($index === 0 || count($row) !== 12) {
                continue;
            }
            if ((int) $row[0] === 1) {
                $raster = ['width' => (int) $row[8], 'height' => (int) $row[9]];
            }
            if ((int) $row[0] !== 5 || (float) $row[10] < 0 || trim($row[11]) === '') {
                continue;
            }
            [$left, $top, $width, $height] = array_map('intval', array_slice($row, 6, 4));
            $key = implode(':', array_slice($row, 1, 4));
            $lines[$key] = max($lines[$key] ?? 0, $height);
            $reasons = [];
            if ((float) $row[10] < 70) {
                $reasons[] = 'low_word_confidence';
            }
            if ($height > 200 || $width > 1800) {
                $reasons[] = 'oversized_word_box';
            }
            if ($reasons !== []) {
                $total++;
                if (count($regions) < 200) {
                    $regions[] = ['bbox_xywh' => [$left, $top, $width, $height], 'confidence' => (float) $row[10], 'word' => mb_substr($row[11], 0, 80), 'reasons' => $reasons];
                }
            }
        }
        $heights = array_values($lines);
        sort($heights);
        $median = $heights === [] ? 0 : $heights[intdiv(count($heights), 2)];

        return ['coordinate_space' => 'OCR raster pixels', 'raster_size' => $raster, 'region_count' => $total, 'regions' => $regions,
            'sample_truncated' => $total > count($regions), 'line_count' => count($lines),
            'tall_line_count' => $median === 0 ? 0 : count(array_filter($heights, fn ($h) => $h > $median * 2)),
            'missing_or_merged_lines_verified' => false];
    }

    private function distance(array $a, array $b): int
    {
        $row = range(0, count($b));
        foreach ($a as $i => $character) {
            $next = [$i + 1];
            foreach ($b as $j => $other) {
                $next[] = min($next[$j] + 1, $row[$j + 1] + 1, $row[$j] + (int) ($character !== $other));
            }
            $row = $next;
        }

        return $row[count($b)];
    }
}
