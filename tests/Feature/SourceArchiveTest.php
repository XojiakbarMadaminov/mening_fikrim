<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentSource;
use App\Models\SourceArchiveObservation;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzScraper;
use App\Services\Documents\DocumentImporter;
use App\Services\Scraping\ArchiveCapture;
use App\Services\Scraping\ArchivedSourceReprocessor;
use App\Services\Scraping\ArchiveException;
use App\Services\Scraping\SourceArchive;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\FixtureInputs;
use Tests\Fixtures\LexResponses;
use Tests\Fixtures\MinimalPdf;
use Tests\Fixtures\VirtualLexHttpClient;
use Tests\TestCase;

class SourceArchiveTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('legal_sources');
        config(['source_archive.enabled' => true, 'source_archive.retention_confirmed' => true]);
    }

    public function test_html_pdf_hash_addressing_private_storage_and_duplicate_bytes(): void
    {
        $archive = app(SourceArchive::class);
        foreach (['html' => '<html><body>Original public legal text.</body></html>', 'pdf' => "%PDF-1.4\ncontrolled bytes\n%%EOF\n"] as $format => $bytes) {
            $first = $archive->retain($bytes, $format, $format === 'pdf' ? 'application/pdf' : 'text/html');
            $second = $archive->retain($bytes, $format, 'application/octet-stream');
            $this->assertSame($first->id, $second->id);
            $this->assertSame($bytes, $archive->read($first));
            $this->assertSame(hash('sha256', $bytes), $first->sha256);
            $this->assertStringStartsWith('lex/'.$format.'/', $first->storage_path);
            $this->assertSame('private', Storage::disk('legal_sources')->getVisibility($first->storage_path));
        }
        $this->assertDatabaseCount('source_archive_blobs', 2);
        $this->assertTrue($archive->audit()['ok']);
        $this->assertFalse(config('filesystems.disks.legal_sources.serve'));
    }

    public function test_failed_disk_write_never_creates_a_successful_blob_reference(): void
    {
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('allFiles')->with('lex')->andReturn([]);
        $disk->shouldReceive('put')->once()->andThrow(new \RuntimeException('Controlled disk full'));
        Storage::shouldReceive('disk')->with('legal_sources')->andReturn($disk);
        try {
            app(SourceArchive::class)->retain('<html>Original normative text</html>', 'html', 'text/html');
            $this->fail('Failed writes cannot create a blob.');
        } catch (ArchiveException $e) {
            $this->assertSame('archive_write_failed', $e->reason);
        }
        $this->assertDatabaseCount('source_archive_blobs', 0);
    }

    public function test_permission_disabled_privacy_state_and_incomplete_files_are_explicit(): void
    {
        $archive = app(SourceArchive::class);
        foreach ([['html', '<html><input type="hidden" name="__VIEWSTATE" value="SECRET"></html>', 'html_contains_unretained_private_state'], ['pdf', '%PDF-1.4 incomplete', 'incomplete_or_invalid_pdf'], ['html', '', 'archive_file_size_invalid']] as [$format,$bytes,$reason]) {
            try {
                $archive->retain($bytes, $format, 'application/octet-stream');
                $this->fail('Unsafe source must be refused.');
            } catch (ArchiveException $e) {
                $this->assertSame($reason, $e->reason);
            }
        }
        config(['source_archive.retention_confirmed' => false]);
        $this->expectException(ArchiveException::class);
        try {
            $archive->retain('<html>Legal text</html>', 'html', 'text/html');
        } finally {
            $this->assertDatabaseCount('source_archive_blobs', 0);
        }
    }

    public function test_quota_failure_has_no_false_blob_and_invalid_paths_are_rejected(): void
    {
        $archive = app(SourceArchive::class);
        config(['source_archive.quota_bytes' => 1]);
        try {
            $archive->retain('<html>Original normative text</html>', 'html', 'text/html');
            $this->fail('Quota must stop the write.');
        } catch (ArchiveException $e) {
            $this->assertSame('archive_quota_exhausted', $e->reason);
        }
        $this->assertDatabaseCount('source_archive_blobs', 0);
        config(['source_archive.quota_bytes' => 10000]);
        $blob = $archive->retain('<html>Original normative text</html>', 'html', 'text/html');
        $blob->storage_path = '../../outside.html';
        $this->expectException(ArchiveException::class);
        $archive->read($blob);
    }

    public function test_missing_corrupt_and_unregistered_blobs_are_reported_without_deletion(): void
    {
        $archive = app(SourceArchive::class);
        $blob = $archive->retain('<html>Original normative text</html>', 'html', 'text/html');
        Storage::disk('legal_sources')->put($blob->storage_path, str_repeat('x', $blob->file_size));
        $this->assertCount(1, $archive->audit()['mismatched']);
        Storage::disk('legal_sources')->delete($blob->storage_path);
        $this->assertCount(1, $archive->audit()['missing']);
        Storage::disk('legal_sources')->put('lex/html/aa/unregistered.html', '<html>orphan</html>');
        $this->assertCount(1, $archive->audit()['orphans']);
        $this->assertDatabaseCount('source_archive_blobs', 1);
    }

    public function test_database_failure_after_atomic_blob_write_leaves_detectable_orphan(): void
    {
        DB::unprepared("CREATE FUNCTION p6_reject_blob() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'controlled insert failure'; END $$; CREATE TRIGGER p6_reject_blob BEFORE INSERT ON source_archive_blobs FOR EACH ROW EXECUTE FUNCTION p6_reject_blob();");
        try {
            app(SourceArchive::class)->retain('<html>Original normative text</html>', 'html', 'text/html');
            $this->fail('Controlled DB failure expected.');
        } catch (QueryException) {
            $this->assertDatabaseCount('source_archive_blobs', 0);
            $this->assertCount(1, app(SourceArchive::class)->audit()['orphans']);
        } finally {
            DB::unprepared('DROP TRIGGER p6_reject_blob ON source_archive_blobs; DROP FUNCTION p6_reject_blob();');
        }
    }

    private function capturedGraph(bool $review = false): array
    {
        $source = DocumentSource::firstOrCreate(['slug' => 'lex'], ['name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);
        $responses = LexResponses::graph();
        if ($review) {
            foreach ($responses as $url => &$html) {
                if (str_contains($url, '/docs/')) {
                    $html = str_replace('</body>', '<img src="/diagram.png"></body>', $html);
                    $html = str_replace('<div id="divCont">', '<div id="divCont"><img src="/diagram.png">', $html);
                }
            }
            unset($html);
        }
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake(array_map(fn ($html) => Http::response($html, 200, ['Content-Type' => 'text/html']), $responses));
        $capture = app(ArchiveCapture::class);
        $capture->begin($source->id, '8506802');
        $candidate = app(LexUzScraper::class)->fetch('8506802');
        $at = new DateTimeImmutable('2026-10-29T12:00:00+05:00');
        $result = app(DocumentImporter::class)->import($source->id, $candidate, $at);
        $archive = $capture->finish($candidate, $result, null, $at);
        $this->assertSame(4, $archive['archived']);

        return [$source, SourceArchiveObservation::findOrFail($archive['observation_ids'][0])];
    }

    public function test_complete_archived_graph_reprocess_is_offline_dry_run_idempotent_and_freshness_safe(): void
    {
        [$source,$observation] = $this->capturedGraph();
        $before = DocumentContent::orderBy('id')->get(['id', 'content_hash', 'quality_status', 'last_fetched_at'])->toArray();
        $beforeDoc = Document::first()->last_checked_at;
        Http::fake(fn () => throw new \RuntimeException('Network forbidden in archived reprocess'));
        $reprocessor = app(ArchivedSourceReprocessor::class);
        $this->assertSame('processed', $reprocessor->reprocess($observation, true)['state']);
        $this->assertSame($before, DocumentContent::orderBy('id')->get(['id', 'content_hash', 'quality_status', 'last_fetched_at'])->toArray());
        $this->assertSame('processed', $reprocessor->reprocess($observation)['state']);
        $this->assertSame('processed', $reprocessor->reprocess($observation)['state']);
        $this->assertSame($before, DocumentContent::orderBy('id')->get(['id', 'content_hash', 'quality_status', 'last_fetched_at'])->toArray());
        $this->assertEquals($beforeDoc, Document::first()->last_checked_at);
        $this->assertSame(0, Artisan::call('documents:reprocess', ['--limit' => 1, '--dry-run' => true]));
        $this->assertSame(1, json_decode(Artisan::output(), true)['selected']);
        $this->assertSame(1, Artisan::call('documents:reprocess', ['--limit' => 31]));
    }

    public function test_missing_archived_graph_does_not_delete_content(): void
    {
        [$source,$observation] = $this->capturedGraph();
        $hashes = DocumentContent::orderBy('id')->pluck('content_hash', 'id')->all();
        Storage::disk('legal_sources')->delete($observation->blob->storage_path);
        $result = app(ArchivedSourceReprocessor::class)->reprocess($observation);
        $this->assertSame('failed', $result['state']);
        $this->assertSame('archive_blob_missing', $result['reason']);
        $this->assertSame($hashes, DocumentContent::orderBy('id')->pluck('content_hash', 'id')->all());
    }

    public function test_reprocessing_review_original_never_overwrites_verified_text(): void
    {
        $source = DocumentSource::create(['slug' => 'lex', 'name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);
        app(DocumentImporter::class)->import($source->id, FixtureInputs::seed(), new DateTimeImmutable('2026-10-29T11:00:00+05:00'));
        $hashes = DocumentContent::orderBy('id')->pluck('content_hash', 'id')->all();
        [$source,$observation] = $this->capturedGraph(true);
        $this->assertSame('processed', app(ArchivedSourceReprocessor::class)->reprocess($observation)['state']);
        $this->assertSame($hashes, DocumentContent::orderBy('id')->pluck('content_hash', 'id')->all());
        $this->assertSame(3, DocumentContent::whereNotNull('pending_candidate')->count());
        $this->assertSame(3, DocumentContent::where('quality_status', 'verified')->count());
    }

    public function test_archived_pdf_uses_existing_extractor_without_network(): void
    {
        $source = DocumentSource::create(['slug' => 'lex', 'name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);
        $card = FixtureInputs::read('p0/source_fixtures.json')['8506802']['metadata'];
        $card['Язык акта'] = 'Узбекский (Л)';
        $html = '<html><body><script>showIframeCard(\'/uz/actinfo/card1/12345\');PDFObject.embed(\'/pdffile/12345\');</script></body></html>';
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake(['https://lex.uz/uz/docs/12345' => Http::response($html, 200, ['Content-Type' => 'text/html']),
            'https://lex.uz/uz/actinfo/card1/12345' => Http::response(LexResponses::card($card), 200),
            'https://lex.uz/pdffile/12345' => Http::response(MinimalPdf::bytes(), 200, ['Content-Type' => 'application/pdf'])]);
        $capture = app(ArchiveCapture::class);
        $capture->begin($source->id, '12345');
        $candidate = app(LexUzScraper::class)->fetch('12345');
        $at = new DateTimeImmutable('2026-10-29T12:00:00+05:00');
        $result = app(DocumentImporter::class)->import($source->id, $candidate, $at);
        $archive = $capture->finish($candidate, $result, null, $at);
        $this->assertSame(2, $archive['archived'], json_encode($archive));
        $before = DocumentContent::first();
        $this->assertSame('needs_review', $before->quality_status);
        $this->assertStringContainsString('Article 20', $before->content);
        Http::fake(fn () => throw new \RuntimeException('No network allowed'));
        $outcome = app(ArchivedSourceReprocessor::class)->reprocess(SourceArchiveObservation::findOrFail($archive['observation_ids'][0]));
        $this->assertSame('processed', $outcome['state'], json_encode($outcome));
        $this->assertSame(0, $outcome['network_requests']);
        $this->assertSame($before->content_hash, DocumentContent::first()->content_hash);
        $this->assertSame(1, Document::count());
    }
}
