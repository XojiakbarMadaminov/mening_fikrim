<?php

namespace App\Scraping\LexUz;

use DOMElement;
use DOMNode;
use DOMText;
use Symfony\Component\DomCrawler\Crawler;

class HtmlMarkdownParser
{
    private array $issues = [];

    private array $headings = [];

    private array $tables = [];

    private array $tableIds = [];

    public function parse(string $html): array
    {
        $this->issues = [];
        $this->headings = ['h1' => 0, 'h2' => 0, 'h3' => 0];
        $this->tables = [];
        $this->tableIds = [];
        $parseStart = hrtime(true);
        $crawler = new Crawler($html, 'https://lex.uz');
        $root = $crawler->filter('#divCont');
        if ($root->count() !== 1) {
            throw new SourceReviewException('Expected one Lex.uz #divCont document body.');
        }
        $removed = $root->filter('.lx_elem2,.COMMENT,.COMMENTLEXUZ,.CHANGES_ORIGINS,.INDEXES_ON_REF,script,style');
        $removedCount = $removed->count();
        $removedChars = 0;
        foreach ($removed as $node) {
            $removedChars += mb_strlen($node->textContent);
            if ((new Crawler($node))->filter('.ACT_TITLE,.CLAUSE_DEFAULT')->count()) {
                $this->issues[] = 'cleanup_normative_node_review';
            }
            $node->parentNode?->removeChild($node);
        }
        foreach ($root->filter('label[id]') as $label) {
            if (in_array($label->getAttribute('id'), ['s527', 's1089', 's1104', 's5987', 's5988'], true) && $label->parentNode !== $root->getNode(0)) {
                $label->parentNode?->parentNode?->removeChild($label->parentNode);
            }
        }
        $superscripts = [];
        foreach ($root->filter('sup') as $sup) {
            $text = $this->compact($sup->textContent);
            $superscripts[] = $text;
            if ($text === '') {
                $sup->parentNode?->removeChild($sup);
            } elseif (preg_match('/^[0-9+()\-]+$/D', $text)) {
                $sup->textContent = strtr($text, array_combine(str_split('0123456789+-()'), preg_split('//u', '⁰¹²³⁴⁵⁶⁷⁸⁹⁺⁻⁽⁾', -1, PREG_SPLIT_NO_EMPTY)));
            } else {
                $sup->textContent = '^{'.$text.'}';
                $this->issues[] = 'unsupported_superscript';
            }
        }
        $images = [];
        foreach ($root->filter('img') as $image) {
            $images[] = ['src' => $image->getAttribute('src'), 'alt' => $image->getAttribute('alt')];
        }
        if ($images !== []) {
            $this->issues[] = 'unextracted_images';
        }
        $source = $this->render($root->getNode(0), false);
        $markdown = trim(preg_replace('/\n[\t ]*\n(?:[\t ]*\n)+/u', "\n\n", $this->render($root->getNode(0), true)))."\n";
        preg_match_all('/[\p{L}\p{N}]+/u', $source, $sourceTokens);
        preg_match_all('/[\p{L}\p{N}]+/u', preg_replace('/\[T\d+:R\d+C\d+;rowspan=\d+;colspan=\d+\]/', '', $markdown), $outputTokens);
        $fidelity = $sourceTokens[0] === $outputTokens[0];
        if (! $fidelity) {
            $this->issues[] = 'html_token_order_or_loss';
        }
        $metadataOnly = (bool) preg_match('/Текст акта приводится на|The text of the act is given in/ui', $source);
        if (! $metadataOnly && $this->headings['h1'] === 0) {
            $this->issues[] = 'missing_document_heading';
        }
        if (mb_strlen(trim($source)) < 80) {
            $this->issues[] = 'suspiciously_short_body';
        }
        $issues = array_values(array_unique($this->issues));
        sort($issues);

        return ['content' => $markdown, 'metadata_only' => $metadataOnly, 'quality' => [
            'format' => 'html', 'complete' => $fidelity && trim($source) !== '',
            'structure_verified' => $issues === [] && ! $metadataOnly,
            'issues' => $issues, 'chars' => mb_strlen($markdown), 'headings' => $this->headings,
            'source_token_count' => count($sourceTokens[0]), 'output_token_count' => count($outputTokens[0]),
            'token_fidelity' => $fidelity, 'removed_editorial_nodes' => $removedCount, 'removed_editorial_chars' => $removedChars, 'timings' => ['html_parse_seconds' => round((hrtime(true) - $parseStart) / 1e9, 6)],
            'superscripts' => $superscripts, 'images' => $images,
            'table_count' => count($this->tables), 'tables' => $this->tables,
        ]];
    }

    private function render(DOMNode $node, bool $markdown): string
    {
        if ($node instanceof DOMText) {
            return preg_replace('/\s+/u', ' ', $node->textContent);
        }
        if (! $node instanceof DOMElement) {
            return '';
        }
        if ($node->tagName === 'br') {
            return "\n";
        }
        if ($node->tagName === 'img') {
            return ''; // Presence is reported as a blocking quality issue, never silently admitted.
        }
        if ($node->tagName === 'table') {
            return $this->table($node, $markdown);
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->render($child, $markdown);
        }
        $classes = preg_split('/\s+/', $node->getAttribute('class'));
        $level = in_array('ACT_TITLE', $classes, true) ? 1
            : (in_array('TEXT_HEADER_DEFAULT', $classes, true) || preg_match('/APPENDIX/', $node->getAttribute('class')) ? 2 : (in_array('CLAUSE_DEFAULT', $classes, true) ? 3 : 0));
        if ($level > 0 && trim($text) === '') {
            return '';
        }
        if ($level > 0) {
            if ($markdown) {
                $this->headings['h'.$level]++;
            }

            return "\n\n".($markdown ? str_repeat('#', $level).' ' : '').trim($text)."\n\n";
        }
        if (in_array($node->tagName, ['div', 'p', 'li', 'ul', 'ol', 'section'], true)) {
            return "\n\n".trim($text)."\n\n";
        }

        return $text;
    }

    /** Keep direct rows only: nested row text must not be emitted a second time. */
    private function table(DOMElement $table, bool $markdown): string
    {
        $path = $table->getNodePath();
        $id = $this->tableIds[$path] ??= count($this->tableIds) + 1;
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $row) {
            $parent = $row->parentNode;
            while ($parent && ! ($parent instanceof DOMElement && $parent->tagName === 'table')) {
                $parent = $parent->parentNode;
            }
            if ($parent?->isSameNode($table)) {
                $rows[] = $row;
            }
        }
        $complex = (new \DOMXPath($table->ownerDocument))->query('.//table|.//*[@rowspan > 1 or @colspan > 1]', $table)->length > 0;
        $occupied = [];
        $inventory = [];
        $lines = [];
        $width = 0;
        $spans = 0;
        foreach ($rows as $r => $row) {
            $line = [];
            $column = 1;
            foreach ((new \DOMXPath($row->ownerDocument))->query('./td|./th', $row) as $cell) {
                while (isset($occupied[$r + 1][$column])) {
                    $column++;
                }
                $rs = max(1, (int) $cell->getAttribute('rowspan'));
                $cs = max(1, (int) $cell->getAttribute('colspan'));
                if ($rs > 1000 || $cs > 1000 || count($inventory) > 50000) {
                    throw new SourceReviewException('HTML table geometry exceeds bounded parsing limits.');
                }
                for ($rr = $r + 1; $rr < $r + 1 + $rs; $rr++) {
                    for ($cc = $column; $cc < $column + $cs; $cc++) {
                        if (isset($occupied[$rr][$cc])) {
                            $this->issues[] = 'table_span_overlap';
                        }
                        $occupied[$rr][$cc] = true;
                    }
                }
                if ($r + $rs > count($rows)) {
                    $this->issues[] = 'table_span_out_of_bounds';
                }
                $text = '';
                foreach ($cell->childNodes as $child) {
                    $text .= $this->render($child, $markdown);
                }
                $inventory[] = ['row' => $r + 1, 'column' => $column, 'rowspan' => $rs, 'colspan' => $cs, 'text' => $this->compact($cell->textContent)];
                $spans += $rs > 1 || $cs > 1 ? 1 : 0;
                $prefix = $markdown && $complex ? '[T'.$id.':R'.($r + 1).'C'.$column.';rowspan='.$rs.';colspan='.$cs.'] ' : '';
                $line[] = $prefix.($complex ? trim($text) : $this->compact($text));
                $width = max($width, $column + $cs - 1);
                $column += $cs;
            }
            $lines[] = $line;
        }
        if ($complex) {
            $this->issues[] = 'complex_table_semantics_review';
        }
        if ($markdown) {
            $this->tables[] = ['table' => $id, 'rows' => count($rows), 'columns' => $width, 'span_count' => $spans, 'cells' => $inventory, 'nested' => (new \DOMXPath($table->ownerDocument))->query('.//table', $table)->length > 0];
        }
        $output = [];
        foreach ($lines as $i => $line) {
            if (! $markdown || $complex) {
                $output[] = implode("\n", $line);
            } else {
                $output[] = '| '.implode(' | ', array_map(fn ($cell) => str_replace('|', '\\|', $cell), array_pad($line, $width, ''))).' |';
                if ($i === 0) {
                    $output[] = '| '.implode(' | ', array_fill(0, $width, '---')).' |';
                }
            }
        }

        return "\n\n".implode("\n", $output)."\n\n";
    }

    private function compact(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
