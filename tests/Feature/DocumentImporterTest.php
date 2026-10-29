<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Jobs\ImportDocumentJob;
use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentIdentifier;
use App\Models\DocumentSource;
use App\Models\ScrapeRunItem;
use App\Services\Documents\DocumentImporter;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class DocumentImporterTest extends TestCase
{
    use DatabaseMigrations;

    private DocumentSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = DocumentSource::create(['name' => 'Lex.uz', 'slug' => 'lex', 'type' => 'scraper', 'base_url' => 'https://lex.uz']);
    }

    private function import(?array $input = null, string $date = '2026-10-28T12:00:00+05:00', ?int $sourceId = null): array
    {
        return app(DocumentImporter::class)->import($sourceId ?? $this->source->id, $input ?? FixtureInputs::seed(), new DateTimeImmutable($date));
    }

    public function test_real_four_identifier_fixture_maps_to_one_document_and_three_full_texts(): void
    {
        $result = $this->import();
        $this->assertSame('created', $result['action']);
        $this->assertTrue($result['complete']);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_identifiers', 4);
        $this->assertDatabaseCount('document_contents', 3);
        $this->assertSame(['en', 'uz-Cyrl', 'uz-Latn'], DocumentContent::orderBy('language')->pluck('language')->all());
        $this->assertDatabaseHas('document_identifiers', ['external_id' => '8507863', 'language' => 'ru']);
        $this->assertDatabaseMissing('document_contents', ['language' => 'ru']);
        $this->assertSame('-8506802', Document::first()->external_id);
    }

    public function test_repeated_import_is_idempotent_and_checks_can_advance_without_content_change(): void
    {
        $first = $this->import();
        $hashes = DocumentContent::pluck('content_hash', 'id')->all();
        $second = $this->import(date: '2026-10-29T12:00:00+05:00');
        $this->assertSame($first['document_id'], $second['document_id']);
        $this->assertSame('unchanged', $second['action']);
        $this->assertSame($hashes, DocumentContent::pluck('content_hash', 'id')->all());
        $this->assertSame('2026-10-29', Document::first()->last_checked_at->format('Y-m-d'));
    }

    public function test_all_source_entrypoints_reuse_persisted_anchor(): void
    {
        $input = FixtureInputs::seed();
        $id = $this->import($input)['document_id'];
        foreach (['8506802', '-8506802', '8506870', '8507863'] as $entry) {
            $input['entry_id'] = $entry;
            $this->assertSame($id, $this->import($input)['document_id']);
        }
        $this->assertDatabaseCount('documents', 1);
        $this->assertSame('-8506802', Document::first()->external_id);
    }

    public function test_source_namespace_does_not_collide_for_identical_external_ids(): void
    {
        $other = DocumentSource::create(['name' => 'Another source', 'slug' => 'other', 'type' => 'scraper']);
        $first = $this->import();
        $second = $this->import(sourceId: $other->id);
        $this->assertNotSame($first['document_id'], $second['document_id']);
        $this->assertDatabaseCount('documents', 2);
        $this->assertDatabaseCount('document_identifiers', 8);
    }

    public function test_one_review_language_is_retained_alongside_verified_languages_and_is_incomplete(): void
    {
        $input = FixtureInputs::seed();
        $input['contents']['en']['quality']['issues'] = ['extraction_failed'];
        $result = $this->import($input);
        $this->assertFalse($result['complete']);
        $this->assertSame('review', $result['languages']['en']['state']);
        $this->assertDatabaseCount('document_contents', 3);
        $this->assertDatabaseHas('document_contents', ['language' => 'en', 'quality_status' => 'needs_review']);
        $this->assertNull(Document::first()->last_checked_at);
        $this->assertFalse(Document::first()->metadata['validation']['complete']);
        $fixed = $this->import();
        $this->assertTrue($fixed['complete']);
        $this->assertDatabaseCount('document_contents', 3);
        $this->assertNotNull(Document::first()->last_checked_at);
    }

    public function test_missing_required_text_cannot_borrow_an_old_success_to_mark_complete(): void
    {
        $this->import();
        $before = DocumentContent::where('language', 'en')->first()->getAttributes();
        $checked = Document::first()->last_checked_at;
        $input = FixtureInputs::seed();
        unset($input['contents']['en']);
        $input['contents']['uz-Latn']['content'] .= "\nQo‘shimcha tekshirilgan matn.\n";
        $result = $this->import($input, '2026-10-29T12:00:00+05:00');
        $this->assertFalse($result['complete']);
        $this->assertSame(['missing_required_language'], $result['languages']['en']['errors']);
        $this->assertSame($before, DocumentContent::where('language', 'en')->first()->getAttributes());
        $this->assertEquals($checked, Document::first()->last_checked_at);
        $this->assertStringContainsString('Qo‘shimcha', DocumentContent::where('language', 'uz-Latn')->first()->content);
    }

    public function test_truncated_text_never_overwrites_previous_valid_content(): void
    {
        $this->import();
        $before = DocumentContent::where('language', 'en')->first()->getAttributes();
        $input = FixtureInputs::seed();
        $input['contents']['en']['content'] = '# Partial';
        $result = $this->import($input);
        $this->assertContains('suspected_truncation', $result['languages']['en']['errors']);
        $this->assertFalse($result['complete']);
        $this->assertSame($before, DocumentContent::where('language', 'en')->first()->getAttributes());
    }

    public function test_content_hash_changes_without_creating_a_history_row(): void
    {
        $this->import();
        $before = DocumentContent::where('language', 'en')->first();
        $input = FixtureInputs::seed();
        $input['contents']['en']['content'] .= "\nVerified fixture update.\n";
        $result = $this->import($input);
        $after = $before->fresh();
        $this->assertSame('updated', $result['action']);
        $this->assertSame($before->id, $after->id);
        $this->assertNotSame($before->content_hash, $after->content_hash);
        $this->assertSame(hash('sha256', $after->content), $after->content_hash);
        $this->assertDatabaseCount('document_contents', 3);
    }

    public function test_crlf_normalization_does_not_change_hash_or_markdown_structure(): void
    {
        $this->import();
        $input = FixtureInputs::seed();
        foreach ($input['contents'] as &$content) {
            $content['content'] = str_replace("\n", "\r\n", $content['content']);
        }
        $this->assertSame('unchanged', $this->import($input)['action']);
    }

    public function test_conflicting_mappings_fail_closed_without_automatic_merge(): void
    {
        $this->import();
        $existing = DocumentIdentifier::where('external_id', '8506870')->first();
        $other = Document::create(['source_id' => $this->source->id, 'external_id' => 'separate', 'title' => 'Separate document', 'status' => 'active']);
        $existing->update(['document_id' => $other->id]);
        $snapshot = Document::orderBy('id')->get()->toArray();
        try {
            $this->import();
            $this->fail('Conflicting identifiers must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('identity', $e->errors());
        }
        $this->assertEquals($snapshot, Document::orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_database_failure_after_first_language_insert_rolls_back_every_write(): void
    {
        $inserts = 0;
        DocumentContent::creating(function () use (&$inserts) {
            if (++$inserts === 2) {
                throw new \RuntimeException('Controlled persistence failure after first language write.');
            }
        });
        try {
            $this->import();
            $this->fail('Expected persistence fault.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Controlled persistence', $e->getMessage());
        } finally {
            DocumentContent::flushEventListeners();
        }
        $this->assertSame(2, $inserts);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_identifiers', 0);
        $this->assertDatabaseCount('document_contents', 0);
    }

    public function test_unknown_metadata_preserves_entire_existing_document(): void
    {
        $this->import();
        $snapshot = Document::with('contents', 'identifiers')->first()->toArray();
        $input = FixtureInputs::seed();
        $input['status_evidence']['state'] = 'unrecognized';
        try {
            $this->import($input);
            $this->fail('Unknown status must fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }
        $this->assertEquals($snapshot, Document::with('contents', 'identifiers')->first()->toArray());
    }

    public function test_pending_active_and_revoked_transitions_preserve_text(): void
    {
        $input = FixtureInputs::seed();
        $input['status_evidence']['effective_at'] = '2027-01-01';
        $this->import($input);
        $this->assertSame(DocumentStatus::Pending, Document::pending()->first()->status);
        $hashes = DocumentContent::pluck('content_hash', 'id')->all();
        $this->import($input, '2027-01-01T00:00:00+05:00');
        $this->assertSame(DocumentStatus::Active, Document::active()->first()->status);
        $input['status_evidence']['state'] = 'revoked';
        $this->import($input, '2027-02-01T00:00:00+05:00');
        $this->assertSame(DocumentStatus::Inactive, Document::inactive()->first()->status);
        $this->assertSame($hashes, DocumentContent::pluck('content_hash', 'id')->all());
    }

    public function test_actual_scan_metadata_date_wins_warning_persists_and_bad_ocr_cannot_replace_old_text(): void
    {
        $input = FixtureInputs::seed('-8553692');
        $input['text_effective_at'] = '2027-01-01'; // Manually verified in P0/P0.1; no new OCR run.
        $doc = Document::create(['source_id' => $this->source->id, 'external_id' => '-8553692', 'title' => $input['title'], 'status' => 'pending', 'last_checked_at' => '2026-10-20T12:00:00+05:00']);
        // Controlled prior-good state tests safe update; this synthetic text is not certified OCR.
        $old = $doc->contents()->create(['language' => 'uz-Cyrl', 'title' => 'Prior validated test text', 'content' => 'Prior validated test text', 'content_hash' => hash('sha256', 'Prior validated test text'), 'quality_status' => 'verified']);
        $result = $this->import($input);
        $this->assertFalse($result['complete']);
        $this->assertSame(DocumentStatus::Active, $doc->fresh()->status);
        $this->assertSame('2026-10-23', $doc->fresh()->effective_at->format('Y-m-d'));
        $this->assertSame('effective_date_mismatch', $doc->fresh()->metadata['validation']['warnings'][0]['code']);
        $this->assertSame($old->content_hash, $old->fresh()->content_hash);
        $this->assertSame('2026-10-20', $doc->fresh()->last_checked_at->format('Y-m-d'));
    }

    public function test_all_bad_languages_cannot_create_an_empty_document(): void
    {
        $input = FixtureInputs::seed();
        $input['contents'] = [];
        $result = $this->import($input);
        $this->assertSame('rejected', $result['action']);
        $this->assertFalse($result['complete']);
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_new_verified_language_keeps_original_canonical_anchor(): void
    {
        $input = FixtureInputs::seed();
        $id = $this->import($input)['document_id'];
        $node = $input['identity'][0];
        $node['external_id'] = '!new-alias';
        $node['language'] = 'fr';
        $node['availability'] = 'full_text';
        $node['links'] = array_column($input['identity'], 'external_id');
        foreach ($input['identity'] as &$oldNode) {
            $oldNode['links'][] = '!new-alias';
        }
        $input['identity'][] = $node;
        $input['contents']['fr'] = $input['contents']['en']; // Controlled new-source variant, not a real French Lex text.
        $this->assertSame($id, $this->import($input)['document_id']);
        $this->assertSame('-8506802', Document::first()->external_id);
        $this->assertDatabaseCount('document_identifiers', 5);
    }

    public function test_real_postgresql_exception_mid_update_rolls_back_metadata_identifiers_and_contents(): void
    {
        $this->import();
        $snapshot = Document::with('contents', 'identifiers')->first()->toArray();
        $input = FixtureInputs::seed();
        $input['contents']['en']['content'] .= "\nChanged first language before DB failure.\n";
        $input['contents']['uz-Cyrl']['content'] .= "\nChanged second language.\n";
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION p1_fail_content_write() RETURNS trigger AS $$
            BEGIN
                IF NEW.language = 'uz-Cyrl' THEN
                    RAISE EXCEPTION 'P1 controlled PostgreSQL write failure';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER p1_content_fault BEFORE UPDATE ON document_contents
            FOR EACH ROW EXECUTE FUNCTION p1_fail_content_write();
            SQL);
        try {
            $this->import($input, '2026-10-29T12:00:00+05:00');
            $this->fail('Expected PostgreSQL fault.');
        } catch (QueryException $e) {
            $this->assertSame('P0001', $e->errorInfo[0]);
        } finally {
            DB::unprepared('DROP TRIGGER p1_content_fault ON document_contents; DROP FUNCTION p1_fail_content_write();');
        }
        $this->assertEquals($snapshot, Document::with('contents', 'identifiers')->first()->toArray());
    }

    public function test_persisted_requisites_cannot_be_replaced_by_a_different_but_internally_consistent_graph(): void
    {
        $this->import();
        $input = FixtureInputs::seed();
        foreach ($input['identity'] as &$node) {
            $node['requisites']['bodies'][0]['number'] = 'another-act';
        }
        $this->expectException(ValidationException::class);
        $this->import($input);
    }

    public function test_verified_identifiers_cannot_silently_disappear_from_a_smaller_graph(): void
    {
        $this->import();
        $input = FixtureInputs::seed();
        $input['identity'] = array_values(array_filter($input['identity'], fn ($node) => $node['external_id'] === '8506802'));
        $input['identity'][0]['links'] = ['8506802'];
        $this->expectException(ValidationException::class);
        $this->import($input);
    }

    public function test_stale_verification_cannot_overwrite_a_newer_successful_check(): void
    {
        $this->import();
        $snapshot = Document::with('contents')->first()->toArray();
        try {
            $this->import(date: '2026-10-27T12:00:00+05:00');
            $this->fail('Stale verification accepted.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('verification', $e->errors());
        }
        $this->assertEquals($snapshot, Document::with('contents')->first()->toArray());
    }

    public function test_incomplete_queue_job_persists_review_after_valid_languages_commit(): void
    {
        $input = FixtureInputs::seed();
        unset($input['contents']['en']);
        $job = new ImportDocumentJob($this->source->id, $input, '2026-10-28T12:00:00+05:00');
        $job->handle(app(DocumentImporter::class));
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame('review', ScrapeRunItem::first()->status);
        $this->assertDatabaseCount('document_contents', 2);
        $this->assertFalse(Document::first()->metadata['validation']['complete']);
        $this->assertNull(Document::first()->last_checked_at);
    }

    public function test_metadata_only_translation_payload_is_never_persisted_as_content(): void
    {
        $input = FixtureInputs::seed();
        $input['contents']['ru'] = $input['contents']['en'];
        $result = $this->import($input);
        $this->assertTrue($result['complete']);
        $this->assertSame('metadata_only', $result['languages']['ru']['state']);
        $this->assertDatabaseMissing('document_contents', ['language' => 'ru']);
    }

    public function test_explicitly_fetched_new_inactive_document_retains_its_legal_status_and_text(): void
    {
        $input = FixtureInputs::seed();
        $input['status_evidence']['state'] = 'revoked';
        $this->import($input);
        $this->assertSame(DocumentStatus::Inactive, Document::first()->status);
        $this->assertDatabaseCount('document_contents', 3);
    }
}
