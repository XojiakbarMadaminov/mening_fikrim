<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentSource;
use App\Scraping\Contracts\DocumentScraper;
use App\Services\Documents\DocumentImporter;
use App\Services\Scraping\ScrapeRunManager;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class CorpusCollectionTest extends TestCase
{
    use DatabaseMigrations;

    private DocumentSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = DocumentSource::create(['name' => 'Lex.uz', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
    }

    private function input(bool $review = false): array
    {
        $input = FixtureInputs::seed();
        if ($review) {
            foreach ($input['contents'] as &$content) {
                $content['quality']['issues'] = ['complex_table_semantics_review'];
                $content['quality']['structure_verified'] = false;
            }
        }

        return $input;
    }

    private function save(array $input, string $at = '2026-10-28T12:00:00+05:00'): array
    {
        return app(DocumentImporter::class)->import($this->source->id, $input, new DateTimeImmutable($at));
    }

    private function recheck(array $options = []): array
    {
        $this->assertSame(0, Artisan::call('documents:quality-recheck', $options));

        return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_all_review_languages_are_persisted_with_original_codes_and_independent_legal_status(): void
    {
        $input = $this->input(true);
        $input['status_evidence']['effective_at'] = '2027-01-01';
        $result = $this->save($input);
        $this->assertSame('created', $result['action']);
        $this->assertSame('imported', $result['import_outcome']);
        $this->assertTrue($result['persistence_complete']);
        $this->assertFalse($result['complete']);
        $this->assertDatabaseCount('document_contents', 3);
        $this->assertSame('pending', Document::first()->status->value);
        $this->assertSame('needs_review', Document::first()->quality_status);
        foreach (DocumentContent::all() as $content) {
            $this->assertSame('needs_review', $content->quality_status);
            $this->assertContains('complex_table_semantics_review', $content->quality_issues);
            $this->assertSame(hash('sha256', $content->content), $content->content_hash);
            $this->assertNotEmpty($content->extraction_metadata['source_url']);
            $this->assertNotNull($content->quality_checked_at);
        }
        $this->assertNull(Document::first()->last_checked_at);
    }

    public function test_verified_content_is_stored_and_mixed_language_quality_does_not_verify_the_document(): void
    {
        $input = $this->input();
        $input['contents']['en']['quality']['issues'] = ['pdf_reading_order_unverified'];
        $this->save($input);
        $this->assertSame(['needs_review', 'verified', 'verified'], DocumentContent::orderBy('language')->pluck('quality_status')->all());
        $this->assertSame('needs_review', Document::first()->quality_status);
    }

    public function test_review_reimport_and_all_canonical_aliases_reuse_one_document_and_one_row_per_language(): void
    {
        $input = $this->input(true);
        $first = $this->save($input);
        $hashes = DocumentContent::pluck('content_hash', 'id')->all();
        foreach (array_column($input['identity'], 'external_id') as $id) {
            $input['entry_id'] = $id;
            $result = $this->save($input);
            $this->assertSame($first['document_id'], $result['document_id']);
            $this->assertSame('unchanged', $result['action']);
        }
        $this->assertSame($hashes, DocumentContent::pluck('content_hash', 'id')->all());
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_identifiers', 4);
        $this->assertDatabaseCount('document_contents', 3);
    }

    public function test_json_numeric_diagnostics_do_not_mark_identical_content_as_updated_after_round_trip(): void
    {
        $input = $this->input(true);
        $input['metadata']['diagnostics'] = ['image_coverage' => 0.0, 'confidence' => 100.0, 'fraction' => 0.125];
        $this->save($input);
        $this->assertSame('unchanged', $this->save($input)['action']);
    }

    public function test_lower_quality_different_text_is_retained_as_candidate_without_overwriting_verified_content(): void
    {
        $this->save($this->input());
        $before = DocumentContent::where('language', 'en')->first();
        $input = $this->input();
        $input['contents']['en']['content'] .= "\nUncertain source table extraction.\n";
        $input['contents']['en']['quality']['issues'] = ['pdf_structure_unverified'];
        $this->save($input, '2026-10-29T12:00:00+05:00');
        $after = $before->fresh();
        $this->assertSame($before->content, $after->content);
        $this->assertSame($before->content_hash, $after->content_hash);
        $this->assertSame('verified', $after->quality_status);
        $this->assertEquals($before->last_fetched_at, $after->last_fetched_at);
        $this->assertStringContainsString('Uncertain source table', $after->pending_candidate['content']);
        $this->assertContains('pdf_structure_unverified', $after->pending_candidate['quality_issues']);
        $this->assertSame('needs_review', Document::first()->quality_status);
        $this->assertSame('unchanged', $this->save($input, '2026-10-30T12:00:00+05:00')['action']);
    }

    public function test_identical_review_text_also_cannot_downgrade_verified_classification(): void
    {
        $this->save($this->input());
        $this->save($this->input(true));
        $this->assertSame(3, DocumentContent::where('quality_status', 'verified')->count());
        $this->assertSame(3, DocumentContent::whereNotNull('pending_candidate')->count());
    }

    public function test_a_stale_result_cannot_replace_or_resolve_a_newer_held_candidate(): void
    {
        $this->save($this->input());
        $review = $this->input(true);
        $review['contents']['en']['content'] .= "\nNewer uncertain extraction.\n";
        $this->save($review, '2026-10-30T12:00:00+05:00');
        $before = DocumentContent::where('language', 'en')->first();
        $result = $this->save($this->input(), '2026-10-29T12:00:00+05:00');
        $this->assertContains('stale_fetch', $result['languages']['en']['errors']);
        $this->assertFalse($result['complete']);
        $this->assertSame($before->pending_candidate, $before->fresh()->pending_candidate);
        $this->assertSame($before->content_hash, $before->fresh()->content_hash);
    }

    public function test_new_verified_extraction_promotes_review_and_resolves_a_held_candidate(): void
    {
        $this->save($this->input(true));
        $ids = DocumentContent::pluck('id')->all();
        $this->save($this->input());
        $this->assertSame('verified', Document::first()->quality_status);
        $this->assertSame($ids, DocumentContent::pluck('id')->all());
        $this->save($this->input(true));
        $this->save($this->input());
        $this->assertSame(0, DocumentContent::whereNotNull('pending_candidate')->count());
        $this->assertNotEmpty(DocumentContent::first()->extraction_metadata['resolved_candidate']);
    }

    public function test_empty_or_meaningless_extraction_never_creates_fake_content_and_failure_is_explicit(): void
    {
        foreach (['', " \n ", '--- !!! 123', 'aaaaaaaaaaaaaaaaaaaa'] as $text) {
            $input = $this->input();
            foreach ($input['contents'] as &$content) {
                $content['content'] = $text;
            }
            $result = $this->save($input);
            $this->assertSame('extraction_failed', $result['import_outcome']);
            $this->assertSame('extraction_failed', $result['languages']['en']['quality_status']);
            $this->assertNull($result['document_id']);
        }
        $this->assertDatabaseCount('document_contents', 0);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_one_failed_extraction_does_not_mark_another_valid_language_as_failed(): void
    {
        $input = $this->input();
        $input['contents']['en']['content'] = '';
        $result = $this->save($input);
        $this->assertSame('extraction_failed', $result['languages']['en']['quality_status']);
        $this->assertSame('verified', $result['languages']['uz-Latn']['quality_status']);
        $this->assertSame('needs_review', Document::first()->quality_status);
        $this->assertDatabaseCount('document_contents', 2);
    }

    public function test_meaningful_text_with_replacement_character_is_retained_for_review_but_nul_bytes_are_not_stored(): void
    {
        $input = $this->input();
        $input['contents']['en']['content'] .= "\nSource text with a replacement \u{FFFD} character.\n";
        $this->save($input);
        $content = DocumentContent::where('language', 'en')->first();
        $this->assertSame('needs_review', $content->quality_status);
        $this->assertContains('invalid_text', $content->quality_issues);
        $before = $content->content;
        $input['contents']['en']['content'] = "Text with forbidden NUL \0 data";
        $result = $this->save($input);
        $this->assertSame('extraction_failed', $result['languages']['en']['quality_status']);
        $this->assertSame($before, $content->fresh()->content);
    }

    public function test_review_queue_item_is_persisted_without_failed_job_and_next_import_runs(): void
    {
        $manager = app(ScrapeRunManager::class);
        $run = $manager->start($this->source, 'full', 2, ['8506802', '-6257288']);
        $other = FixtureInputs::seed('-6257288');
        // This old text fixture has no retained graph. Explicit controlled reciprocal proof for this offline test only.
        foreach ($other['identity'] as &$node) {
            $node['links'] = array_column($other['identity'], 'external_id');
        }
        unset($node);
        $other['diagnostics'] = ['primary_format' => 'html', 'quality' => array_column($other['contents'], 'quality')];
        $review = $this->input(true);
        $review['diagnostics'] = ['primary_format' => 'html', 'quality' => array_map(fn ($c) => $c['quality'], $review['contents'])];
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->with('8506802', null)->once()->andReturn($review);
        $fake->shouldReceive('fetch')->with('-6257288', null)->once()->andReturn($other);
        $this->app->instance(DocumentScraper::class, $fake);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--stop-when-empty' => true, '--tries' => 3])->assertSuccessful();
        $summary = $manager->summary($run->fresh());
        $this->assertSame(1, $summary['imported_with_needs_review']);
        $this->assertSame(2, $summary['persisted_imported'], json_encode($run->items()->get()->toArray()));
        $this->assertSame(0, $summary['technical_failed']);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('documents', 2);
        $this->assertSame(3, DocumentContent::where('quality_status', 'needs_review')->count());
        $this->assertFalse($run->fresh()->canComplete());
    }

    public function test_new_explicit_inactive_document_is_collected_without_changing_legal_status(): void
    {
        $input = $this->input(true);
        $input['status_evidence']['state'] = 'revoked';
        $this->save($input);
        $this->assertSame('inactive', Document::first()->status->value);
        $this->assertSame('needs_review', Document::first()->quality_status);
    }

    public function test_empty_extraction_is_durable_in_the_queue_ledger_without_fake_content_or_technical_failure(): void
    {
        $input = $this->input();
        foreach ($input['contents'] as &$content) {
            $content['content'] = '';
        }
        unset($content);
        $input['diagnostics'] = ['primary_format' => 'html', 'quality' => array_map(fn ($c) => $c['quality'], $input['contents'])];
        $run = app(ScrapeRunManager::class)->start($this->source, 'full', 1, ['8506802']);
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->once()->andReturn($input);
        $this->app->instance(DocumentScraper::class, $fake);
        $this->artisan('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--stop-when-empty' => true])->assertSuccessful();
        $item = $run->items()->first();
        $this->assertSame('review', $item->status);
        $this->assertSame('extraction_failed', $item->outcome['import_outcome']);
        $this->assertSame('extraction_failed', $item->language_results['en']['quality_status']);
        $summary = app(ScrapeRunManager::class)->summary($run->fresh());
        $this->assertSame(1, $summary['extraction_failed']);
        $this->assertSame(3, $summary['failed_content_representations']);
        $this->assertSame(0, $summary['technical_failed']);
        $this->assertDatabaseCount('document_contents', 0);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_recheck_limit_status_dry_run_repeat_and_fetch_timestamp_are_safe(): void
    {
        $this->save($this->input(true));
        $before = DocumentContent::orderBy('id')->get()->toArray();
        $dry = $this->recheck(['--limit' => 1, '--dry-run' => true]);
        $this->assertSame(1, $dry['checked']);
        $this->assertSame($before, DocumentContent::orderBy('id')->get()->toArray());
        $checked = $this->recheck(['--limit' => 1]);
        $this->assertSame(1, $checked['needs_review']);
        $this->assertSame($before[0]['last_fetched_at'], DocumentContent::first()->toArray()['last_fetched_at']);
        $this->assertSame(0, $this->recheck(['--status' => 'verified'])['selected']);
        $this->assertSame(0, $this->recheck(['--status' => 'extraction_failed'])['selected']);
        $this->assertSame(0, $this->recheck()['changed']);
        $this->assertDatabaseCount('document_contents', 3);
        $this->assertSame(0, Artisan::call('documents:quality-recheck', ['--limit' => 30]));
        $this->assertSame(1, Artisan::call('documents:quality-recheck', ['--limit' => 31]));
    }

    public function test_recheck_only_promotes_when_retained_validation_evidence_passes(): void
    {
        $this->save($this->input());
        $content = DocumentContent::first();
        $content->update(['quality_status' => 'needs_review', 'quality_issues' => ['previous_review']]);
        $this->assertSame(1, $this->recheck()['verified']);
        $this->assertSame('verified', $content->fresh()->quality_status);
    }

    public function test_local_recheck_of_old_verified_text_does_not_resolve_a_newer_review_candidate(): void
    {
        $this->save($this->input());
        $this->save($this->input(true));
        $hashes = DocumentContent::pluck('content_hash', 'id')->all();
        $this->recheck(['--status' => 'verified']);
        $this->assertSame($hashes, DocumentContent::pluck('content_hash', 'id')->all());
        $this->assertSame(3, DocumentContent::whereNotNull('pending_candidate')->count());
    }

    public function test_failed_local_recheck_retains_stored_text_and_failure_diagnostics(): void
    {
        $this->save($this->input(true));
        $content = DocumentContent::first();
        $content->update(['content' => '!!!', 'content_hash' => hash('sha256', '!!!')]);
        $result = $this->recheck(['--limit' => 1]);
        $this->assertSame(1, $result['extraction_failed']);
        $this->assertSame('!!!', $content->fresh()->content);
        $this->assertNotEmpty($content->fresh()->extraction_metadata['last_failed_recheck']);
        $this->assertDatabaseCount('document_contents', 3);
    }

    public function test_migration_upgrades_populated_legacy_schema_without_changing_text_ids_or_hashes(): void
    {
        $this->save($this->input());
        $unknown = $this->source->documents()->create(['title' => 'Unproven legacy admission', 'status' => 'active']);
        $unknown->contents()->create(['title' => 'Unproven text', 'language' => 'en', 'content' => 'Unproven legacy legal text.', 'content_hash' => hash('sha256', 'Unproven legacy legal text.')]);
        $snapshot = DocumentContent::orderBy('id')->get(['id', 'document_id', 'content', 'content_hash', 'last_fetched_at'])->toArray();
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('document_contents', 'quality_status'));
        $this->artisan('migrate')->assertSuccessful();
        $this->assertSame($snapshot, DocumentContent::orderBy('id')->get(['id', 'document_id', 'content', 'content_hash', 'last_fetched_at'])->toArray());
        $this->assertSame(3, DocumentContent::where('quality_status', 'verified')->count());
        $this->assertSame('needs_review', $unknown->contents()->first()->quality_status);
        $this->assertContains('legacy_quality_unproven', $unknown->contents()->first()->quality_issues);
    }

    public function test_database_rejects_fake_candidates_and_extraction_failed_content_rows(): void
    {
        $this->save($this->input());
        foreach ([['quality_status' => 'extraction_failed'], ['pending_candidate' => json_encode(['quality_status' => null, 'content' => 'Actual content text', 'content_hash' => str_repeat('a', 64), 'quality_issues' => [], 'extraction_metadata' => new \stdClass])]] as $values) {
            try {
                DB::table('document_contents')->where('id', DocumentContent::first()->id)->update($values);
                $this->fail('Expected PostgreSQL constraint rejection.');
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0]);
            }
        }
    }
}
