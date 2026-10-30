<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSource;
use App\Scraping\LexUz\HtmlMarkdownParser;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzParser;
use App\Scraping\LexUz\LexUzScraper;
use App\Scraping\LexUz\SourceReviewException;
use App\Services\Documents\DocumentImporter;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FixtureInputs;
use Tests\Fixtures\LexResponses;
use Tests\Fixtures\VirtualLexHttpClient;
use Tests\TestCase;

/** Synthetic availability cases on a saved real identity graph; no live assertions. */
class LanguageAvailabilityTest extends TestCase
{
    use DatabaseMigrations;

    private function input(array $available): array
    {
        $input = FixtureInputs::seed();
        foreach ($input['identity'] as &$node) {
            $status = $available[$node['language']] ?? 'unknown';
            $node['availability'] = $status === 'available' ? 'full_text' : 'metadata_only';
            $node['availability_evidence'] = ['status' => $status, 'type' => 'synthetic_regression', 'metadata' => ['raw_label' => $node['language']]];
            if ($status !== 'available') {
                unset($input['contents'][$node['language']]);
            }
        }

        return $input;
    }

    private function save(array $input, string $at = '2026-10-29T00:00:00+05:00'): array
    {
        $source = DocumentSource::firstOrCreate(['slug' => 'lex'], ['name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);

        return app(DocumentImporter::class)->import($source->id, $input, new DateTimeImmutable($at));
    }

    public function test_single_language_with_explicitly_absent_translations_has_verified_quality(): void
    {
        $result = $this->save($this->input(['uz-Latn' => 'available', 'uz-Cyrl' => 'not_available', 'ru' => 'not_available', 'en' => 'not_available']));
        $this->assertSame('not_available', $result['languages']['ru']['availability_status']);
        $this->assertSame('not_attempted', $result['languages']['ru']['extraction_outcome']);
        $this->assertArrayNotHasKey('quality_status', $result['languages']['ru']);
        $doc = Document::first();
        $this->assertSame('verified', $doc->quality_status);
        $this->assertSame('complete', $doc->language_coverage_status);
        $this->assertDatabaseCount('document_contents', 1);
        $this->assertSame('active', $doc->status->value);
    }

    public function test_unknown_translations_are_coverage_uncertainty_and_not_quality_failure(): void
    {
        $this->save($this->input(['uz-Latn' => 'available']));
        $doc = Document::first();
        $this->assertSame('verified', $doc->quality_status);
        $this->assertSame('unknown', $doc->language_coverage_status);
        $this->assertSame('unknown', $doc->metadata['language_availability']['ru']['status']);
        $this->assertDatabaseCount('document_contents', 1);
    }

    public function test_two_and_three_language_documents_keep_independent_representations(): void
    {
        $input = $this->input(['uz-Latn' => 'available', 'uz-Cyrl' => 'available']);
        $this->save($input);
        $this->assertDatabaseCount('document_contents', 2);
        $input = $this->input(['uz-Latn' => 'available', 'uz-Cyrl' => 'available', 'en' => 'available']);
        $this->save($input, '2026-10-29T01:00:00+05:00');
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_contents', 3);
        $this->assertSame('verified', Document::first()->quality_status);
    }

    public function test_confirmed_available_but_failed_extraction_is_incomplete_without_empty_row(): void
    {
        $input = $this->input(['uz-Latn' => 'available', 'uz-Cyrl' => 'available']);
        unset($input['contents']['uz-Cyrl']);
        $result = $this->save($input);
        $this->assertSame('extraction_failed', $result['languages']['uz-Cyrl']['quality_status']);
        $this->assertSame('available', $result['languages']['uz-Cyrl']['availability_status']);
        $this->assertSame('failed', $result['languages']['uz-Cyrl']['extraction_outcome']);
        $this->assertSame('needs_review', Document::first()->quality_status);
        $this->assertSame('incomplete', Document::first()->language_coverage_status);
        $this->assertDatabaseCount('document_contents', 1);
    }

    public function test_review_quality_is_not_promoted_by_missing_translation_resolution(): void
    {
        $input = $this->input(['uz-Latn' => 'available', 'ru' => 'not_available']);
        $input['contents']['uz-Latn']['quality']['issues'] = ['complex_table_semantics_review'];
        $this->save($input);
        $this->assertSame('needs_review', Document::first()->quality_status);
        $this->assertContains('complex_table_semantics_review', Document::first()->contents->first()->quality_issues);
    }

    public function test_failed_reprocessing_keeps_verified_text_but_confirmed_coverage_is_incomplete(): void
    {
        $input = $this->input(['uz-Latn' => 'available', 'uz-Cyrl' => 'available']);
        $this->save($input);
        $hashes = Document::first()->contents->pluck('content_hash', 'id')->all();
        unset($input['contents']['uz-Cyrl']);
        $this->save($input, '2026-10-29T01:00:00+05:00');
        $doc = Document::first();
        $this->assertSame($hashes, $doc->contents->pluck('content_hash', 'id')->all());
        $this->assertSame(['verified', 'verified'], $doc->contents->pluck('quality_status')->all());
        $this->assertSame('needs_review', $doc->quality_status);
        $this->assertSame('incomplete', $doc->language_coverage_status);
        $this->save($this->input(['uz-Latn' => 'available', 'uz-Cyrl' => 'available']), '2026-10-29T02:00:00+05:00');
        $this->assertSame('verified', Document::first()->quality_status);
    }

    public function test_reobserved_source_changes_preserve_previous_availability_and_trusted_content(): void
    {
        $input = $this->input(['uz-Latn' => 'available', 'uz-Cyrl' => 'available']);
        $this->save($input);
        $hashes = Document::first()->contents->pluck('content_hash', 'id')->all();
        $input = $this->input(['uz-Latn' => 'available', 'uz-Cyrl' => 'not_available']);
        $this->save($input, '2026-10-29T01:00:00+05:00');
        $doc = Document::first();
        $this->assertSame($hashes, $doc->contents->pluck('content_hash', 'id')->all());
        $this->assertSame('available', $doc->metadata['language_availability']['uz-Cyrl']['previous_observation']['status']);
        $this->assertSame('not_available', $doc->metadata['language_availability']['uz-Cyrl']['status']);
        $this->assertSame('verified', $doc->quality_status);
    }

    public function test_actual_reported_russian_labels_are_normalized_without_guessing_uzbek_script(): void
    {
        foreach (['Ruscha', 'Русча', 'Русский'] as $raw) {
            $this->assertSame('ru', app(LexUzParser::class)->language(['Язык акта' => $raw]));
        }
        $this->expectException(SourceReviewException::class);
        app(LexUzParser::class)->language(['Язык акта' => 'Uzbek']);
    }

    public function test_p6_observed_cyrillic_english_label_remains_distinct_and_raw_evidence_is_preserved(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/p6_english_label.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($fixture['observations']);
        foreach ($fixture['observations'] as $observation) {
            $card = $observation['card'];
            $this->assertSame('Инглизча', $card['Язык акта']);
            $this->assertSame('en', app(LexUzParser::class)->language($card));
            $this->assertSame('Инглизча', $card['Язык акта']);
        }
        $this->assertSame('uz-Cyrl', app(LexUzParser::class)->language(['Язык акта' => 'Узбекский (к)']));
        $this->assertSame('ru', app(LexUzParser::class)->language(['Язык акта' => 'Русча']));
    }

    public function test_observed_uzbek_script_markers_are_normalized_without_guessing_unmarked_uzbek(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/p6_uzbek_script_labels.json')), true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(2, $fixture['observations']);
        foreach ($fixture['observations'] as $observation) {
            $card = $observation['card'];
            $raw = $card['Язык акта'];
            $this->assertSame('uz-Cyrl', app(LexUzParser::class)->language($card));
            $this->assertSame($raw, $card['Язык акта']);
        }
        $this->assertSame('uz-Latn', app(LexUzParser::class)->language(['Язык акта' => 'O‘zbekcha (L)']));
        $this->expectException(SourceReviewException::class);
        app(LexUzParser::class)->language(['Язык акта' => 'O‘zbekcha']);
    }

    public function test_explicit_translation_notice_is_evidence_but_absent_ui_link_is_not(): void
    {
        $parser = app(LexUzParser::class);
        $html = '<div id="divCont">Текст акта приводится на узбекском языке.</div>';
        $parsed = app(HtmlMarkdownParser::class)->parse($html);
        $observation = $parser->availability($html, ['Язык акта' => 'Русский'], $parsed);
        $this->assertSame('not_available', $observation['status']);
        $this->assertStringContainsString('Текст акта', $observation['metadata']['notice']);
        $this->assertCount(1, $parser->links('<nav>English Русский</nav>', '1', ['Язык акта' => 'Русский']));
    }

    public function test_successful_http_fallback_is_review_not_a_fabricated_translation(): void
    {
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        $responses = LexResponses::graph();
        $responses['https://lex.uz/uz/docs/8506802'] = str_replace('>O’zb</div>', '>Рус</div>', $responses['https://lex.uz/uz/docs/8506802']);
        // Two ru controls are ambiguous before fallback; use the sole current-source control.
        $responses['https://lex.uz/uz/docs/8506802'] = '<script>showIframeCard(\'/uz/actinfo/card1/8506802\')</script><a class="docContentHeader__item-link active" title="language">Рус</a><div id="divCont">Fallback</div>';
        Http::fake(array_map(fn ($html) => Http::response($html, 200), $responses));
        $this->expectException(SourceReviewException::class);
        $this->expectExceptionMessage('Source language fallback: requested ru; card uz-Latn');
        app(LexUzScraper::class)->fetch('8506802');
    }
}
