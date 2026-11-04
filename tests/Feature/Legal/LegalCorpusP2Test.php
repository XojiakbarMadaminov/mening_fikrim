<?php

namespace Tests\Feature\Legal;

use App\Jobs\ChunkLegalDocumentJob;
use App\Services\Chunking\ChunkingPipeline;
use App\Services\Legal\ChunkDocumentService;
use App\Services\Legal\LegalChunkVerifier;
use App\Services\Legal\LegalKeywordSearch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LegalCorpusP2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.pgsql_ai' => config('database.connections.pgsql'), 'ai.enabled' => false, 'ai.retrieval_enabled' => false, 'ai.connection' => 'pgsql_ai']);
        DB::extend('pgsql_ai', fn () => DB::connection('pgsql'));
        foreach (glob(database_path('ai-migrations/*.php')) as $path) {
            (require $path)->up();
        }
    }

    private function source(string $language = 'uz-Latn', string $quality = 'verified', ?string $text = null, ?int $doc = null): int
    {
        if ($doc === null) {
            $source = DB::table('document_sources')->insertGetId(['name' => 'Local fixture', 'slug' => 'local-'.uniqid(), 'type' => 'manual', 'is_active' => false]);
            $doc = DB::table('documents')->insertGetId(['source_id' => $source, 'title' => 'Fixture law', 'document_type' => 'Закон', 'status' => 'active', 'metadata' => '{}']);
        }
        $text ??= "1-modda. Huquq\n\n".str_repeat('Mehnat huquqi himoya qilinadi. ', 6);

        return DB::table('document_contents')->insertGetId(['document_id' => $doc, 'language' => $language, 'title' => 'Fixture '.$language,
            'content' => $text, 'content_hash' => hash('sha256', $text), 'quality_status' => $quality, 'quality_issues' => '[]', 'extraction_metadata' => '{}']);
    }

    public function test_exact_100_source_limit_and_resumable_1000_corpus(): void
    {
        $first = $this->source();
        $doc = DB::table('document_contents')->where('id', $first)->value('document_id');
        // Different language keys are supported by corpus schema, keeping this a compact local fixture.
        for ($i = 1; $i < 1000; $i++) {
            $this->source('fixture-'.$i, doc: $doc);
        }
        $this->artisan('legal:chunk-documents', ['--only-pending' => true, '--content-limit' => 100])->assertSuccessful();
        self::assertSame(100, DB::table('document_chunk_sets')->where('is_current', true)->count());
        self::assertSame(900, app(ChunkDocumentService::class)->status(4000)['pending_contents']);
        $this->artisan('legal:chunk-documents', ['--only-pending' => true, '--content-limit' => 500])->assertSuccessful();
        $this->artisan('legal:chunk-documents', ['--only-pending' => true, '--content-limit' => 400])->assertSuccessful();
        self::assertSame(1000, DB::table('document_chunk_sets')->where('is_current', true)->count());
        $before = DB::table('document_chunks')->orderBy('id')->get()->toJson();
        $this->artisan('legal:chunk-documents', ['--only-pending' => true, '--content-limit' => 100])->assertSuccessful();
        self::assertSame($before, DB::table('document_chunks')->orderBy('id')->get()->toJson());
        self::assertSame(0, app(ChunkDocumentService::class)->status(4000)['pending_contents']);
    }

    public function test_version_and_checksum_history_is_preserved_and_only_current_is_searchable(): void
    {
        $id = $this->source();
        $service = app(ChunkDocumentService::class);
        $search = app(LegalKeywordSearch::class);
        $service->process($id, 4000);
        $first = DB::table('document_chunks')->value('id');
        config(['ai.version' => 'legal-v2-next']);
        self::assertSame([], $search->search('mehnat')['results']);
        $service->process($id, 4000);
        self::assertSame(2, DB::table('document_chunk_sets')->count());
        self::assertSame(1, DB::table('document_chunk_sets')->where('is_current', true)->count());
        self::assertNotSame($first, $search->search('mehnat')['results'][0]['id']);
        $new = "1-modda. Yangi\n\n".str_repeat('Taʼlim va yangi qoidalar. ', 6);
        DB::table('document_contents')->where('id', $id)->update(['content' => $new, 'content_hash' => hash('sha256', $new)]);
        self::assertSame([], $search->search('mehnat')['results']);
        $service->process($id, 4000);
        self::assertSame(3, DB::table('document_chunk_sets')->count());
        self::assertSame(3, DB::table('document_chunks')->count());
        self::assertSame([], $search->search('mehnat')['results']);
        self::assertCount(1, $search->search('qoidalar')['results']);
    }

    public function test_keyword_language_quality_category_pagination_and_source_attribution(): void
    {
        $service = app(ChunkDocumentService::class);
        $search = app(LegalKeywordSearch::class);
        foreach (['uz-Latn', 'uz-Cyrl', 'ru'] as $lang) {
            $heading = $lang === 'ru' ? 'Статья 1.' : ($lang === 'uz-Cyrl' ? '1-модда.' : '1-modda.');
            $word = $lang === 'ru' ? 'труд' : ($lang === 'uz-Cyrl' ? 'меҳнат' : 'mehnat');
            $id = $this->source($lang, text: $heading."\n\n".str_repeat($word.' ҳуқуқ huquq. ', 10));
            $service->process($id, 4000);
            $result = $search->search($word, $lang)['results'];
            self::assertCount(1, $result);
            self::assertSame($lang, $result[0]['language']);
            self::assertSame($id, $result[0]['document_content_id']);
            self::assertNull($result[0]['source_url']);
            self::assertSame('Fixture '.$lang, $result[0]['document_title']);
            self::assertSame('1', $result[0]['section_number']);
        }
        $review = $this->source(quality: 'needs_review');
        $service->process($review, 4000);
        self::assertCount(1, $search->search('mehnat', 'uz-Latn', 'structured')['results']);
        self::assertCount(1, $search->search('mehnat', 'uz-Latn', 'needs_review')['results']);
        self::assertSame(2, $search->search('huquq', quality: 'all', limit: 1)['next_page']);
        self::assertSame([], $search->search('mehnat', category: 'Указ')['results']);
        self::assertCount(1, $search->search('mehnat', category: 'Закон')['results']);
        self::assertSame([], $search->search("x' OR 1=1 --", quality: 'all')['results']);
        $this->artisan('legal:search', ['query' => 'mehnat', '--limit' => 51])->assertFailed();
    }

    public function test_source_metadata_and_document_url_changes_invalidate_current_generation(): void
    {
        $id = $this->source();
        $service = app(ChunkDocumentService::class);
        $search = app(LegalKeywordSearch::class);
        $service->process($id, 4000);
        DB::table('document_contents')->where('id', $id)->update(['quality_status' => 'needs_review']);
        self::assertSame([], $search->search('mehnat', quality: 'all')['results']);
        $service->process($id, 4000);
        $doc = DB::table('document_contents')->where('id', $id)->value('document_id');
        DB::table('documents')->where('id', $doc)->update(['source_url' => 'https://example.test/observed']);
        self::assertSame([], $search->search('mehnat', quality: 'all')['results']);
        $service->process($id, 4000);
        self::assertSame('https://example.test/observed', $search->search('mehnat', quality: 'all')['results'][0]['source_url']);
        self::assertSame(3, DB::table('document_chunk_sets')->count());
    }

    public function test_interrupted_transaction_and_duplicate_job_resume_safely(): void
    {
        $id = $this->source();
        $service = app(ChunkDocumentService::class);
        DB::beginTransaction();
        $service->process($id, 4000);
        DB::rollBack();
        self::assertSame(0, DB::table('document_chunks')->count());
        $job = new ChunkLegalDocumentJob($id);
        $job->handle(app(ChunkingPipeline::class));
        $before = DB::table('document_chunks')->get()->toJson();
        $job->handle(app(ChunkingPipeline::class));
        self::assertSame($before, DB::table('document_chunks')->get()->toJson());
        self::assertSame(1, DB::table('document_chunk_sets')->where('is_current', true)->count());
    }

    public function test_failed_generation_has_bounded_attempts_and_new_input_can_recover(): void
    {
        $id = $this->source();
        $service = app(ChunkDocumentService::class);
        DB::statement("CREATE FUNCTION pg_temp.reject_p2() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture'; END $$");
        DB::statement('CREATE TRIGGER reject_p2 BEFORE INSERT ON document_chunks FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_p2()');
        for ($i = 0; $i < 3; $i++) {
            try {
                $service->process($id, 4000);
                self::fail('Expected insert failure');
            } catch (QueryException) {
            }
        }
        self::assertSame('retry_exhausted', $service->process($id, 4000)['status']);
        self::assertSame(3, DB::table('document_chunk_sets')->value('attempts'));
        DB::statement('DROP TRIGGER reject_p2 ON document_chunks');
        DB::table('document_contents')->where('id', $id)->update(['quality_status' => 'needs_review']);
        $service->process($id, 4000);
        self::assertSame(2, DB::table('document_chunk_sets')->count());
        self::assertSame(1, DB::table('document_chunk_sets')->where('is_current', true)->count());
        self::assertSame(0, $service->status(4000)['failed_contents']);
        self::assertSame(1, $service->status(4000)['historical_failed_generations']);
    }

    public function test_independent_verifier_detects_corruption_and_force_repairs_only_derived_rows(): void
    {
        $id = $this->source();
        $service = app(ChunkDocumentService::class);
        $service->process($id, 4000);
        $verifier = app(LegalChunkVerifier::class);
        self::assertTrue($verifier->verify($id)['valid']);
        $source = DB::table('document_contents')->where('id', $id)->value('content');
        DB::table('document_chunks')->where('document_content_id', $id)->update(['content' => 'damaged derived text']);
        self::assertFalse($verifier->verify($id)['valid']);
        $service->process($id, 4000, true);
        self::assertTrue($verifier->verify($id)['valid']);
        self::assertSame($source, DB::table('document_contents')->where('id', $id)->value('content'));
    }

    public function test_queued_profile_drift_is_refused(): void
    {
        $id = $this->source();
        $job = new ChunkLegalDocumentJob($id);
        config(['ai.version' => 'other-profile']);
        $this->expectException(\LogicException::class);
        $job->handle(app(ChunkingPipeline::class));
    }

    public function test_search_refuses_main_connection_without_queries(): void
    {
        config(['ai.connection' => 'pgsql']);
        DB::shouldReceive('connection')->never();
        $this->expectException(\LogicException::class);
        app(LegalKeywordSearch::class)->search('mehnat');
    }
}
