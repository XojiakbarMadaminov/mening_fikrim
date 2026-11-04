<?php

namespace Tests\Fixtures\LegalV1;

class DocumentStructureParser
{
    /** Byte offsets always refer to the unchanged stored Markdown, including whitespace. */
    public function parse(string $text, bool $trustHeadings = true): array
    {
        $sections = [];
        $context = ['section' => null, 'chapter' => null, 'article' => null];
        $current = ['start' => 0, 'type' => 'paragraph', 'number' => null, 'title' => null, 'context' => $context, 'boundaries' => []];
        preg_match_all('/[^\r\n]+(?:\r\n|\r|\n)?|(?:\r\n|\r|\n)/u', $text, $lines, PREG_OFFSET_CAPTURE);
        foreach ($lines[0] as [$line, $offset]) {
            $heading = $trustHeadings ? $this->heading($line) : null;
            if ($heading === null) {
                continue;
            }
            if (in_array($heading['type'], ['part', 'point', 'subpoint'], true) && $context['article'] !== null) {
                $current['boundaries'][] = ['offset' => $offset, ...$heading];

                continue;
            }
            if ($offset > $current['start']) {
                $sections[] = [...$current, 'end' => $offset];
            }
            if ($heading['type'] === 'section') {
                $context = ['section' => $heading, 'chapter' => null, 'article' => null];
            } elseif ($heading['type'] === 'chapter') {
                $context['chapter'] = $heading;
                $context['article'] = null;
            } elseif ($heading['type'] === 'article') {
                $context['article'] = $heading;
            } elseif (in_array($heading['type'], ['title', 'appendix', 'heading'], true)) {
                // An annex or new heading cannot inherit the preceding article by accident.
                $context['article'] = null;
                if ($heading['type'] === 'appendix') {
                    $context['chapter'] = null;
                }
            }
            $current = ['start' => $offset, ...$heading, 'context' => $context, 'boundaries' => []];
        }
        if (strlen($text) > $current['start']) {
            $sections[] = [...$current, 'end' => strlen($text)];
        }

        return $sections;
    }

    private function heading(string $line): ?array
    {
        $plain = trim(preg_replace('/^\s{0,3}#{1,6}\s+/u', '', $line));
        // Match a complete heading prefix only. Never repair OCR numbers or invent identifiers.
        $number = '[0-9]+(?:[.\-][0-9]+|[⁰¹²³⁴⁵⁶⁷⁸⁹]+)*';
        $roman = '(?:[IVXLCDM]+|[0-9]+)';
        $patterns = [
            'article' => ["/^($number)\s*[-–—]\s*(?:modda|модда)(?=[\s.:—–-]|$)/ui", "/^Статья\s+($number)(?=[\s.:—–-]|$)/ui"],
            'chapter' => ["/^($roman)\s*[-–—.]?\s*(?:bob|боб)(?=[\s.:—–-]|$)/ui", "/^Глава\s+($roman)(?=[\s.:—–-]|$)/ui"],
            'section' => ["/^($roman)\s*[-–—.]?\s*(?:bo[‘’ʻʼ'`]?lim|бўлим)(?=[\s.:—–-]|$)/ui", "/^Раздел\s+($roman)(?=[\s.:—–-]|$)/ui"],
            'part' => ["/^($roman)\s*[-–—.]?\s*(?:qism|қисм)(?=[\s.:—–-]|$)/ui", "/^Часть\s+($roman)(?=[\s.:—–-]|$)/ui"],
            'point' => ["/^($number)\.\s+/u", "/^($number)\s*[-–—]\s*(?:band|банд)(?=[\s.:—–-]|$)/ui", "/^Пункт\s+($number)(?=[\s.:—–-]|$)/ui"],
            'subpoint' => ['/^(\d+|[a-zа-яё])\)\s+/ui'],
            'appendix' => ["/^($number)\s*[-–—]?\s*(?:ilova|илова)(?=[\s.:—–-]|$)/ui", "/^Приложение\s+($number)(?=[\s.:—–-]|$)/ui"],
        ];
        foreach ($patterns as $type => $expressions) {
            foreach ($expressions as $expression) {
                if (preg_match($expression, $plain, $match)) {
                    return ['type' => $type, 'number' => $match[1], 'title' => $plain];
                }
            }
        }
        if (preg_match('/^\s{0,3}(#{1,6})\s+/u', $line, $match)) {
            return ['type' => strlen($match[1]) === 1 ? 'title' : 'heading', 'number' => null, 'title' => $plain];
        }

        return null;
    }
}
