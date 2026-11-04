<?php

namespace Tests\Feature\Chunking;

use App\Jobs\ChunkLegalDocumentJob;
use App\Services\Chunking\ChunkingPipeline;
use App\Services\Chunking\PassageVerifier;
use App\Services\Legal\ChunkDocumentService;
use App\Services\Legal\LegalKeywordSearch;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PassageGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.pgsql_ai' => config('database.connections.pgsql'), 'ai.retrieval_enabled' => true, 'ai.connection' => 'pgsql_ai']);
        DB::extend('pgsql_ai', fn () => DB::connection('pgsql'));
        foreach (glob(database_path('ai-migrations/*.php')) as $path) {
            (require $path)->up();
        }
    }

    private function source(?string $text = null, string $language = 'uz-Latn'): int
    {
        $s = DB::table('document_sources')->insertGetId(['name' => 'Local', 'slug' => 'local-'.uniqid(), 'type' => 'manual', 'is_active' => false]);
        $d = DB::table('documents')->insertGetId(['source_id' => $s, 'title' => 'Observed title', 'document_type' => 'Закон', 'status' => 'active', 'metadata' => '{}']);
        $text ??= "# Qonun\n\n1-modda. Huquq\n\n".str_repeat('Fuqarolar huquqi saqlanadi. ', 600);

        return DB::table('document_contents')->insertGetId(['document_id' => $d, 'language' => $language, 'title' => 'Observed title', 'content' => $text, 'content_hash' => hash('sha256', $text), 'quality_status' => 'verified', 'quality_issues' => '[]', 'extraction_metadata' => '{}']);
    }

    public function test_v2_source_rows_remain_immutable_and_v3_is_idempotent(): void
    {
        $id = $this->source();
        $sources = app(ChunkDocumentService::class);
        $sources->process($id, 4000);
        $before = DB::table('document_chunks')->orderBy('id')->get()->toJson();
        $this->artisan('legal:chunk-documents', ['--content-id' => [$id]])->assertSuccessful();
        self::assertSame($before, DB::table('document_chunks')->orderBy('id')->get()->toJson());
        self::assertTrue(app(PassageVerifier::class)->verify($id)['valid']);
        $rows = DB::table('retrieval_passages')->orderBy('id')->get()->toJson();
        $this->artisan('legal:chunk-documents', ['--only-pending' => true, '--content-limit' => 100])->assertSuccessful();
        self::assertSame($rows, DB::table('retrieval_passages')->orderBy('id')->get()->toJson());
        self::assertSame(1, DB::table('document_chunk_sets')->where('layer', 'source')->where('is_current', true)->count());
        self::assertSame(1, DB::table('document_chunk_sets')->where('layer', 'retrieval')->where('is_current', true)->count());
    }

    public function test_atomic_failed_retrieval_profile_retains_the_old_current(): void
    {
        $id = $this->source();
        $pipeline = app(ChunkingPipeline::class);
        $pipeline->process($id, 4000);
        $old = DB::table('document_chunk_sets')->where('layer', 'retrieval')->where('is_current', true)->value('id');
        config(['ai.retrieval_version' => 'legal-v3-next']);
        DB::statement("CREATE FUNCTION pg_temp.reject_passage() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture failure'; END $$");
        DB::statement('CREATE TRIGGER reject_passage BEFORE INSERT ON retrieval_passages FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_passage()');
        try {
            $pipeline->process($id, 4000);
            self::fail('Expected failure');
        } catch (QueryException) {
        }
        self::assertTrue(DB::table('document_chunk_sets')->where('id', $old)->value('is_current'));
        self::assertSame(1, DB::table('document_chunk_sets')->where('layer', 'retrieval')->where('status', 'failed')->count());
        DB::statement('DROP TRIGGER reject_passage ON retrieval_passages');
        $pipeline->process($id, 4000);
        self::assertFalse(DB::table('document_chunk_sets')->where('id', $old)->value('is_current'));
        self::assertTrue(app(PassageVerifier::class)->verify($id)['valid']);
    }

    public function test_changed_source_invalidates_both_layers_and_creates_new_history(): void
    {
        $id = $this->source();
        $pipeline = app(ChunkingPipeline::class);
        $pipeline->process($id, 4000);
        $text = "1-modda. Yangi\n\n".str_repeat('Yangi matn va huquq. ', 30);
        DB::table('document_contents')->where('id', $id)->update(['content' => $text, 'content_hash' => hash('sha256', $text)]);
        self::assertSame(0, DB::table('document_chunk_sets')->where('is_current', true)->count());
        $pipeline->process($id, 4000);
        self::assertSame(4, DB::table('document_chunk_sets')->count());
        self::assertTrue(app(PassageVerifier::class)->verify($id)['valid']);
    }

    public function test_dry_run_new_source_writes_neither_layer(): void
    {
        $id = $this->source();
        $this->artisan('legal:chunk-documents', ['--content-id' => [$id], '--dry-run' => true])->assertSuccessful();
        self::assertSame(0, DB::table('document_chunk_sets')->count());
        self::assertSame(0, DB::table('retrieval_passages')->count());
    }

    public function test_lineage_corruption_is_independently_detected(): void
    {
        $id = $this->source();
        app(ChunkingPipeline::class)->process($id, 4000);
        $row = DB::table('retrieval_passages')->first();
        $spans = json_decode($row->lineage, true);
        $spans[0]['source_chunk_id'] = 999999;
        DB::table('retrieval_passages')->where('id', $row->id)->update(['lineage' => json_encode($spans)]);
        self::assertFalse(app(PassageVerifier::class)->verify($id)['valid']);
    }

    public function test_v3_queue_pins_the_profile_and_builds_both_layers(): void
    {
        $id = $this->source();
        $job = new ChunkLegalDocumentJob($id);
        self::assertSame('legal-v3', $job->chunkingVersion);
        self::assertSame('ai_database', $job->connection);
        self::assertSame('legal-chunking', $job->queue);
        $job->handle(app(ChunkingPipeline::class));
        self::assertTrue(app(PassageVerifier::class)->verify($id)['valid']);
        config(['ai.retrieval_version' => 'legal-v3-next']);
        $this->expectException(\LogicException::class);
        $job->handle(app(ChunkingPipeline::class));
    }

    public function test_retrieval_retry_budget_is_input_specific_and_force_preserves_successful_rows(): void
    {
        $id = $this->source();
        $pipeline = app(ChunkingPipeline::class);
        app(ChunkDocumentService::class)->process($id, 4000);
        DB::statement("CREATE FUNCTION pg_temp.fail_retrieval() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'fixture'; END $$");
        DB::statement('CREATE TRIGGER fail_retrieval BEFORE INSERT ON retrieval_passages FOR EACH ROW EXECUTE FUNCTION pg_temp.fail_retrieval()');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $pipeline->process($id, 4000);
                self::fail('Expected failed transaction');
            } catch (QueryException) {
            }
        }
        self::assertSame('retry_exhausted', $pipeline->process($id, 4000)['status']);
        self::assertSame(0, DB::table('retrieval_passages')->count());
        self::assertSame(1, $pipeline->status(4000)['failed_retrieval_contents']);
        self::assertSame(1, $pipeline->status(4000)['retry_exhausted_retrieval_contents']);
        DB::statement('DROP TRIGGER fail_retrieval ON retrieval_passages');
        DB::table('document_contents')->where('id', $id)->update(['title' => 'Updated observed title']);
        self::assertSame(0, $pipeline->status(4000)['failed_retrieval_contents']);
        $pipeline->process($id, 4000);
        $before = DB::table('retrieval_passages')->orderBy('id')->get()->toJson();
        self::assertSame('unchanged', $pipeline->process($id, 4000, true)['status']);
        self::assertSame($before, DB::table('retrieval_passages')->orderBy('id')->get()->toJson());
        self::assertSame(1, $pipeline->status(4000)['failed_retrieval_generations']);
        self::assertTrue(app(PassageVerifier::class)->verify($id)['valid']);
    }

    public function test_search_returns_only_current_passage_profile_with_lineage_and_filters(): void
    {
        $id = $this->source();
        $pipeline = app(ChunkingPipeline::class);
        $pipeline->process($id, 4000);
        $search = app(LegalKeywordSearch::class);
        $result = $search->search('huquqi', 'uz-Latn', 'eligible', null, 1, 1);
        self::assertCount(1, $result['results']);
        $row = $result['results'][0];
        self::assertSame('legal-v3', $row['chunking_version']);
        self::assertSame('legal-v2', $row['source_chunking_version']);
        self::assertSame($id, $row['document_content_id']);
        self::assertNotEmpty($row['source_chunk_ids']);
        self::assertNull($row['source_url']);
        self::assertSame([], $search->search('huquqi', 'ru', 'all')['results']);
        self::assertSame([], $search->search("x' OR 1=1 --", quality: 'all')['results']);
        config(['ai.retrieval_version' => 'legal-v3-next']);
        self::assertSame([], $search->search('huquqi', 'uz-Latn', 'all')['results']);
        $pipeline->process($id, 4000);
        self::assertSame('legal-v3-next', $search->search('huquqi', 'uz-Latn', 'all')['results'][0]['chunking_version']);
    }

    public function test_main_guard_refuses_pipeline_without_database_queries(): void
    {
        config(['ai.connection' => 'pgsql']);
        DB::shouldReceive('connection')->never();
        $this->expectException(\LogicException::class);
        app(ChunkingPipeline::class)->process(1, 4000);
    }

    public function test_pagination_does_not_advertise_a_page_beyond_the_cap(): void
    {
        $text = '';
        for ($i = 1; $i <= 1002; $i++) {
            $text .= $i."-modda. Chegara\n\nUmumiy qidiruvcap matni.\n\n";
        }
        $id = $this->source($text);
        app(ChunkingPipeline::class)->process($id, 4000);
        self::assertSame(1002, DB::table('retrieval_passages')->count());
        $search = app(LegalKeywordSearch::class);
        self::assertSame(1000, $search->search('qidiruvcap', page: 999, limit: 1)['next_page']);
        self::assertNull($search->search('qidiruvcap', page: 1000, limit: 1)['next_page']);
        $this->expectException(\InvalidArgumentException::class);
        $search->search('qidiruvcap', page: 1001, limit: 1);
    }
}
