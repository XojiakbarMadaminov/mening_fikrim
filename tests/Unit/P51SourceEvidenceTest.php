<?php

namespace Tests\Unit;

use App\Scraping\LexUz\HtmlMarkdownParser;
use App\Scraping\LexUz\LexUzParser;
use App\Services\Documents\DocumentValidator;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class P51SourceEvidenceTest extends TestCase
{
    private function nodes(array $ids): array
    {
        $saved = FixtureInputs::read('p5_1/source_identity_evidence.json');
        $parser = app(LexUzParser::class);

        return array_map(fn ($id) => ['external_id' => $id, 'language' => $parser->language($saved[$id]['card']),
            'availability' => 'full_text', 'links' => array_map('strval', array_column($saved[$id]['links'], 'id')),
            'requisites' => $parser->requisites($saved[$id]['card'])], $ids);
    }

    public function test_real_number_diacritic_conflict_is_not_normalized_into_a_speculative_merge(): void
    {
        $nodes = $this->nodes(['1587862', '1587860', '-1587862']);
        $numbers = array_merge(...array_map(fn ($node) => array_column($node['requisites']['bodies'], 'number'), $nodes));
        $this->assertContains('1-ҚҚ', $numbers);
        $this->assertContains('1-КК', $numbers);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Conflicting component requisites: bodies');
        app(DocumentValidator::class)->identity($nodes, '1587862');
    }

    public function test_real_pdf_viewer_does_not_prove_reciprocal_translation_links(): void
    {
        $nodes = $this->nodes(['8354875', '-8345596', '8345596']);
        $this->assertSame(['8345596'], $nodes[2]['links']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing node or nonreciprocal source link');
        app(DocumentValidator::class)->identity($nodes, '8354875');
    }

    public function test_missing_target_remains_an_identity_failure(): void
    {
        $nodes = $this->nodes(['8354875', '-8345596']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Missing node or nonreciprocal source link');
        app(DocumentValidator::class)->identity($nodes, '8354875');
    }

    public function test_actual_p5_raw_label_diagnostics_now_normalize_to_russian(): void
    {
        $observations = FixtureInputs::read('p5_1/language_label_evidence.json');
        $this->assertCount(3, $observations);
        foreach ($observations as $observation) {
            $this->assertSame('ru', app(LexUzParser::class)->language(['Язык акта' => $observation['raw_label']]));
        }
    }

    public function test_retained_real_translation_notice_proves_absence_without_fake_text(): void
    {
        // Actual P0 extracted notice; this DOM wrapper is an offline reconstruction, not raw HTML.
        $notice = file_get_contents(dirname(__DIR__, 2).'/docs/p0/markdown/8506802_ru.md');
        $html = '<div id="divCont"><p>'.htmlspecialchars($notice).'</p></div>';
        $parsed = app(HtmlMarkdownParser::class)->parse($html);
        $evidence = app(LexUzParser::class)->availability($html, ['Язык акта' => 'Русский'], $parsed);
        $this->assertTrue($parsed['metadata_only']);
        $this->assertSame('not_available', $evidence['status']);
        $this->assertStringContainsString('неофициальный перевод', $evidence['metadata']['notice']);
    }
}
