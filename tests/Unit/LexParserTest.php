<?php

namespace Tests\Unit;

use App\Scraping\LexUz\HtmlMarkdownParser;
use App\Scraping\LexUz\LexUzParser;
use App\Scraping\LexUz\PdfTextExtractor;
use DOMDocument;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Tests\Fixtures\FixtureInputs;
use Tests\Fixtures\LexResponses;
use Tests\TestCase;

class LexParserTest extends TestCase
{
    public function test_historical_revision_is_not_accepted_as_current_document_evidence(): void
    {
        $this->expectException(\RuntimeException::class);
        app(LexUzParser::class)->externalId('https://lex.uz/docs/8506802?ONDATE=01.01.2020');
    }

    public function test_unknown_linked_language_requires_review(): void
    {
        $this->expectException(\RuntimeException::class);
        app(LexUzParser::class)->links('<a class="docContentHeader__item-link" title="language" onclick="openUrl(\'/docs/42\')">fra</a>', '42', []);
    }

    public function test_saved_real_source_excerpt_keeps_heading_and_removes_editorial_ui(): void
    {
        $fixture = FixtureInputs::read('p0/source_fixtures.json')['8506802'];
        $parsed = app(HtmlMarkdownParser::class)->parse($fixture['excerpt_html']);
        $this->assertStringStartsWith('# Strategik', $parsed['content']);
        $this->assertStringNotContainsString('Audioni tinglash', $parsed['content']);
        $this->assertTrue($parsed['quality']['token_fidelity']);
        $this->assertTrue($parsed['quality']['structure_verified']);
    }

    public function test_saved_language_controls_ignore_pdf_download_and_global_interface_menu(): void
    {
        $fixture = FixtureInputs::read('p0/source_fixtures.json')['8506802'];
        $links = app(LexUzParser::class)->links(implode('', $fixture['language_controls']), '8506802', $fixture['metadata']);
        $this->assertSame(['ru', 'en', 'uz-Cyrl', 'uz-Latn'], array_keys($links));
        $this->assertSame('8506870', $links['en']['id']);
        $pdf = FixtureInputs::read('p0/source_fixtures.json')['8557098'];
        $this->assertCount(1, app(LexUzParser::class)->links($pdf['global_interface_menu'], '8557098', $pdf['metadata']));
    }

    public function test_saved_metadata_reconstruction_roundtrips_and_date_precedence_is_not_body_driven(): void
    {
        $metadata = FixtureInputs::read('p0/source_fixtures.json')['-8553692']['metadata'];
        $parser = app(LexUzParser::class);
        $card = $parser->card(LexResponses::card($metadata));
        $this->assertSame($metadata['accepting_bodies'], $card['accepting_bodies']);
        $this->assertSame('2026-10-23', $parser->status($card)['effective_at']);
        $this->assertSame(['2027-01-01'], $parser->wholeEffectiveDates('Ушбу қарор 2027 йил 1 январдан кучга киради.'));
        $this->assertSame([], $parser->wholeEffectiveDates('Ушбу қарорнинг 2 банди 2027 йил 1 январдан кучга киради.'));
    }

    public function test_saved_localized_card_language_is_supported_and_status_requires_listing_corroboration(): void
    {
        $card = array_values(array_filter(FixtureInputs::read('p0_1/acquired.json'), fn ($row) => $row['id'] === '8554326'))[0]['metadata'];
        $parser = app(LexUzParser::class);
        $this->assertSame('uz-Latn', $parser->language($card));
        $this->assertSame('unknown', $parser->status($card)['state']);
        $this->assertSame('active', $parser->status($card, 'Y')['state']);
    }

    public function test_numeric_superscript_headings_and_simple_table_are_preserved(): void
    {
        $html = '<div id="divCont"><div class="ACT_TITLE">Normativ hujjatning asosiy nomi</div><div class="TEXT_HEADER_DEFAULT">1-bob. Umumiy qoidalar</div><div class="CLAUSE_DEFAULT">17<sup>1</sup>-modda. Huquqlar</div><p>1. Ushbu band va kichik bandlarning tartibi saqlanadi va matn to‘liq tekshiriladi.</p><table><tr><th>Nomi</th><th>Qiymat</th></tr><tr><td>Birinchi</td><td>10</td></tr></table></div>';
        $parsed = app(HtmlMarkdownParser::class)->parse($html);
        $this->assertStringContainsString('### 17¹-modda', $parsed['content']);
        $this->assertStringContainsString('| Birinchi | 10 |', $parsed['content']);
        $this->assertTrue($parsed['quality']['structure_verified']);
        $this->assertSame(1, $parsed['quality']['table_count']);
    }

    public function test_real_complex_table_and_unsupported_superscript_require_review(): void
    {
        $html = FixtureInputs::read('p0_1/regression_fixtures.json')['8554309']['html'];
        $parsed = app(HtmlMarkdownParser::class)->parse($html);
        $this->assertContains('complex_table_semantics_review', $parsed['quality']['issues']);
        $this->assertStringContainsString('Nasos', $parsed['content']);
        $math = app(HtmlMarkdownParser::class)->parse('<div id="divCont"><div class="ACT_TITLE">Test</div><p>Formula x<sup>max</sup> va rasm <img src="/diagram.png"></p></div>');
        $this->assertContains('unsupported_superscript', $math['quality']['issues']);
        $this->assertContains('unextracted_images', $math['quality']['issues']);
        $this->assertStringContainsString('x^{max}', $math['content']);
    }

    public function test_partial_text_layer_with_long_header_has_low_body_coverage(): void
    {
        $xml = new DOMDocument;
        $xml->loadXML('<page width="600" height="800"><image left="0" top="0" width="600" height="800"/><text left="0" top="5" height="20">'.str_repeat('Header ', 70).'</text></page>');
        $metrics = app(PdfTextExtractor::class)->geometry($xml->documentElement);
        $this->assertSame(1.0, $metrics['image_coverage']);
        $this->assertSame(0, $metrics['body_chars']);
        $this->assertLessThan(0.35, $metrics['text_vertical_coverage']);
    }

    public function test_invalid_pdf_and_process_failure_leave_no_temporary_source_files(): void
    {
        $directory = sys_get_temp_dir().'/p2-cleanup-test-'.bin2hex(random_bytes(5));
        config(['scraping.temp_directory' => $directory]);
        try {
            try {
                app(PdfTextExtractor::class)->extract('%PDF-1.4 broken controlled test', 'application/pdf', 'uz-Latn');
                $this->fail('Broken PDF must fail.');
            } catch (ProcessFailedException $e) {
                $this->assertSame([], glob($directory.'/pdf-*'));
            }
            $this->expectException(\RuntimeException::class);
            app(PdfTextExtractor::class)->extract('<html>not PDF</html>', 'text/html', 'uz-Latn');
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
