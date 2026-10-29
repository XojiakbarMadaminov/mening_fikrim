<?php

namespace Tests\Unit;

use App\Scraping\LexUz\HtmlMarkdownParser;
use App\Scraping\LexUz\LexUzParser;
use App\Scraping\LexUz\PdfQualityInspector;
use App\Services\Documents\DocumentValidator;
use DOMDocument;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class P3ParserQualityTest extends TestCase
{
    public function test_three_blank_headers_do_not_change_normative_words_or_create_empty_markdown_headings(): void
    {
        // Controlled reconstruction of P2's observed three blank TEXT_HEADER_DEFAULT nodes.
        $source = FixtureInputs::read('p0/source_fixtures.json')['-6257288']['excerpt_html'];
        $html = str_replace('<div id="divCont">', '<div id="divCont">'.str_repeat('<div class="TEXT_HEADER_DEFAULT"><br> </div>', 3), $source);
        $parser = app(HtmlMarkdownParser::class);
        $baseline = $parser->parse($source);
        $parsed = $parser->parse($html);
        $this->assertSame($baseline['content'], $parsed['content']);
        $this->assertDoesNotMatchRegularExpression('/^#{1,3}\s*$/m', $parsed['content']);
        $this->assertTrue($parsed['quality']['token_fidelity']);
    }

    public function test_complex_table_retains_all_cell_text_coordinates_and_spans_without_admitting_semantics(): void
    {
        $html = '<div id="divCont"><div class="ACT_TITLE">Hujjat</div><p>'.str_repeat('Normativ matn saqlanadi. ', 8).'</p><table><tr><th rowspan="2">Organ</th><th colspan="2">Vazifalar</th></tr><tr><td>A</td><td>B</td></tr></table></div>';
        $parsed = app(HtmlMarkdownParser::class)->parse($html);
        $table = $parsed['quality']['tables'][0];
        $this->assertCount(4, $table['cells']);
        $this->assertSame(2, $table['cells'][2]['column']);
        $this->assertStringContainsString('[T1:R1C1;rowspan=2;colspan=1] Organ', $parsed['content']);
        $this->assertTrue($parsed['quality']['token_fidelity']);
        $this->assertFalse($parsed['quality']['structure_verified']);
    }

    public function test_nested_table_text_is_not_duplicated_and_both_cell_inventories_remain(): void
    {
        $html = '<div id="divCont"><div class="ACT_TITLE">Hujjat</div><p>'.str_repeat('Matn ', 30).'</p><table><tr><td>Outer <table><tr><td>NestedUnique</td></tr></table> Tail</td></tr></table></div>';
        $parsed = app(HtmlMarkdownParser::class)->parse($html);
        $this->assertSame(1, substr_count($parsed['content'], 'NestedUnique'));
        $this->assertSame(2, $parsed['quality']['table_count']);
        $this->assertTrue($parsed['quality']['token_fidelity']);
        $this->assertContains('complex_table_semantics_review', $parsed['quality']['issues']);
    }

    public function test_images_in_a_table_and_mathematical_superscripts_stay_review(): void
    {
        $parsed = app(HtmlMarkdownParser::class)->parse('<div id="divCont"><div class="ACT_TITLE">Hujjat</div><table><tr><td>x<sup>max</sup><img src="/legal-diagram.png" alt="Scheme"></td></tr></table></div>');
        $this->assertContains('unsupported_superscript', $parsed['quality']['issues']);
        $this->assertContains('unextracted_images', $parsed['quality']['issues']);
        $this->assertStringContainsString('x^{max}', $parsed['content']);
        $this->assertCount(1, $parsed['quality']['images']);
    }

    public function test_cleanup_that_contains_a_normative_heading_is_explicitly_flagged(): void
    {
        $parsed = app(HtmlMarkdownParser::class)->parse('<div id="divCont"><div class="ACT_TITLE">Hujjat</div><div class="COMMENT"><div class="CLAUSE_DEFAULT">Important heading</div></div><p>'.str_repeat('Matn ', 30).'</p></div>');
        $this->assertContains('cleanup_normative_node_review', $parsed['quality']['issues']);
        $this->assertGreaterThan(0, $parsed['quality']['removed_editorial_chars']);
    }

    public function test_parallel_text_columns_are_suspicious_instead_of_automatically_verified(): void
    {
        $xml = new DOMDocument;
        $lines = '';
        for ($i = 0; $i < 4; $i++) {
            $y = 100 + $i * 25;
            $lines .= '<text left="20" top="'.$y.'" width="220" height="20">Left</text><text left="330" top="'.$y.'" width="220" height="20">Right</text>';
        }
        $xml->loadXML('<page width="600" height="800">'.$lines.'</page>');
        $result = app(PdfQualityInspector::class)->page($xml->documentElement);
        $this->assertTrue($result['multi_column_suspected']);
        $this->assertFalse($result['reading_order_verified']);
        $this->assertFalse($result['missing_or_merged_lines_verified']);
    }

    public function test_new_real_metadata_is_not_forced_to_the_old_p2_effective_date(): void
    {
        $source = FixtureInputs::read('p3/source_changes.json')['-8553692'];
        $evidence = app(LexUzParser::class)->status($source['metadata']);
        $result = app(DocumentValidator::class)->status($evidence, new \DateTimeImmutable('2026-10-28T12:00:00+05:00'), '2027-01-01');
        $this->assertSame('2027-01-01', $result['effective_at']);
        $this->assertSame('pending', $result['status']->value);
        $this->assertSame([], $result['warnings']);
        $this->assertSame('html', $source['format']);
    }

    public function test_ocr_review_regions_preserve_coordinates_and_do_not_claim_line_accuracy(): void
    {
        $header = "level\tpage_num\tblock_num\tpar_num\tline_num\tword_num\tleft\ttop\twidth\theight\tconf\ttext\n";
        $tsv = $header."1\t1\t0\t0\t0\t0\t0\t0\t1600\t2400\t-1\t\n";
        $tsv .= "5\t1\t1\t1\t1\t1\t100\t200\t90\t25\t60\tШубҳали\n";
        $tsv .= "5\t1\t1\t1\t2\t1\t100\t240\t1901\t250\t95\tMerged\n";
        $result = app(PdfQualityInspector::class)->ocrRegions($tsv);
        $this->assertSame(2, $result['region_count']);
        $this->assertSame([100, 200, 90, 25], $result['regions'][0]['bbox_xywh']);
        $this->assertSame(2400, $result['raster_size']['height']);
        $this->assertFalse($result['sample_truncated']);
        $this->assertFalse($result['missing_or_merged_lines_verified']);
    }

    public function test_verified_localized_language_labels_normalize_apostrophes_case_and_whitespace(): void
    {
        $parser = app(LexUzParser::class);
        foreach (['O‘zbekcha (L)', 'Oʻzbekcha (L)', "O'ZBEKCHA (l)", '  O‘zbekcha (L)  '] as $label) {
            $this->assertSame('uz-Latn', $parser->language(['Язык акта' => $label]));
        }
    }

    public function test_existing_manual_gold_excerpts_have_reproducible_unicode_cer_and_wer_without_document_certification(): void
    {
        $gold = FixtureInputs::read('p0_1/ocr_gold.json');
        $a = app(PdfQualityInspector::class)->compareGold($gold[0]['reference'], $gold[0]['ocr_excerpt']);
        $b = app(PdfQualityInspector::class)->compareGold($gold[1]['reference'], $gold[1]['ocr_excerpt']);
        $this->assertSame(0.0, $a['CER']);
        $this->assertSame(2, $b['character_errors']);
        $this->assertSame(105, $b['reference_chars']);
        $this->assertGreaterThan(0, $b['WER']);
        $this->assertFalse($a['document_certified']);
        $this->assertFalse($b['document_certified']);
    }
}
