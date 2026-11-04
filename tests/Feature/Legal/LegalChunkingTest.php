<?php

namespace Tests\Feature\Legal;

use App\Jobs\ChunkLegalDocumentJob;
use App\Models\DocumentChunk;
use App\Providers\AiDevelopmentServiceProvider;
use App\Services\Chunking\ChunkingPipeline;
use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\ChunkDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class LegalChunkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.pgsql_ai' => config('database.connections.pgsql'), 'ai.enabled' => false, 'ai.retrieval_enabled' => false, 'ai.connection' => 'pgsql_ai']);
        // Share the SAME dedicated test transaction; never connect to AI DEV or main during tests.
        DB::extend('pgsql_ai', fn () => DB::connection('pgsql'));
        foreach (glob(database_path('ai-migrations/*.php')) as $path) {
            (require $path)->up();
        }
    }

    private function content(string $language = 'uz-Latn', ?int $documentId = null): int
    {
        if ($documentId === null) {
            $sourceId = DB::table('document_sources')->insertGetId(['name' => 'Fixture', 'slug' => 'fixture-'.uniqid(), 'type' => 'manual', 'is_active' => false]);
            $documentId = DB::table('documents')->insertGetId(['source_id' => $sourceId, 'title' => 'Qonun', 'status' => 'active', 'metadata' => '{}', 'source_url' => 'https://example.test/doc']);
        }
        $text = "# Qonun\n\n## I bob. Umumiy\n\n### 1-modda. Qoida\n\n".str_repeat('Huquqiy mazmun saqlanadi. ', 10);

        return DB::table('document_contents')->insertGetId(['document_id' => $documentId, 'language' => $language, 'title' => 'Qonun', 'content' => $text, 'content_hash' => hash('sha256', $text), 'quality_status' => 'verified', 'quality_issues' => '[]', 'extraction_metadata' => '{"source_url":"https://example.test/'.$language.'"}']);
    }

    public function test_command_is_idempotent_and_keeps_translations_separate(): void
    {
        $id = $this->content();
        $docId = DB::table('document_contents')->where('id', $id)->value('document_id');
        $ru = $this->content('ru', $docId);
        $this->artisan('legal:chunk-documents', ['--document-id' => [$docId]])->assertSuccessful();
        $before = DB::table('document_chunks')->orderBy('id')->get()->toJson();
        $this->artisan('legal:chunk-documents', ['--document-id' => [$docId]])->assertSuccessful();
        self::assertSame($before, DB::table('document_chunks')->orderBy('id')->get()->toJson());
        self::assertSame(2, DB::table('document_chunk_sets')->count());
        self::assertSame(['ru', 'uz-Latn'], DB::table('document_chunks')->distinct()->orderBy('language')->pluck('language')->all());
        self::assertSame($ru, (int) DocumentChunk::where('language', 'ru')->first()->sourceContent->id);
        foreach ([$id, $ru] as $contentId) {
            self::assertSame(DB::table('document_contents')->where('id', $contentId)->value('content'), DB::table('document_chunks')->where('document_content_id', $contentId)->orderBy('chunk_index')->pluck('content')->implode(''));
        }
    }

    public function test_changed_checksum_replaces_only_its_own_profile_and_language(): void
    {
        $id = $this->content();
        $docId = DB::table('document_contents')->where('id', $id)->value('document_id');
        $ru = $this->content('ru', $docId);
        $service = app(ChunkDocumentService::class);
        $service->process($id, 4000);
        $service->process($id, 800);
        $service->process($ru, 4000);
        $ruRows = DB::table('document_chunks')->where('document_content_id', $ru)->get()->toJson();
        $changed = "1-modda. Yangi matn\n\n".str_repeat('Oʻzgargan mazmun. ', 15);
        DB::table('document_contents')->where('id', $id)->update(['content' => $changed, 'content_hash' => hash('sha256', $changed)]);
        $this->artisan('legal:chunk-documents', ['--only-pending' => true, '--limit' => 1])->assertSuccessful();
        self::assertSame($changed, DB::table('document_chunks')->where('document_content_id', $id)->where('source_checksum', hash('sha256', $changed))->orderBy('chunk_index')->pluck('content')->implode(''));
        self::assertSame($ruRows, DB::table('document_chunks')->where('document_content_id', $ru)->get()->toJson());
        self::assertSame(4, DB::table('document_chunk_sets')->count());
        self::assertSame(2, DB::table('document_chunk_sets')->where('is_current', true)->count());
    }

    public function test_pending_detects_metadata_change_even_with_same_checksum(): void
    {
        $id = $this->content();
        app(ChunkDocumentService::class)->process($id, 4000);
        DB::table('document_contents')->where('id', $id)->update(['quality_status' => 'needs_review']);
        $this->artisan('legal:chunk-documents', ['--only-pending' => true])->assertSuccessful();
        self::assertSame(['needs_review'], app(ChunkDocumentService::class)->currentChunks()->distinct()->pluck('k.quality_status')->all());
    }

    public function test_dry_run_status_force_and_limits(): void
    {
        $id = $this->content();
        $this->content();
        $this->artisan('legal:chunk-documents', ['--dry-run' => true])->assertSuccessful();
        self::assertSame(0, DB::table('document_chunks')->count());
        $this->artisan('legal:chunk-documents', ['--limit' => 1])->assertSuccessful();
        self::assertSame(1, DB::table('document_chunk_sets')->count());
        $originalIds = DB::table('document_chunks')->pluck('id')->all();
        $this->artisan('legal:chunk-documents', ['--limit' => 1, '--force' => true])->assertSuccessful();
        self::assertNotSame($originalIds, DB::table('document_chunks')->pluck('id')->all());
        $before = DB::table('document_chunks')->count();
        $this->artisan('legal:chunk-documents', ['--status' => true])->assertSuccessful();
        self::assertSame($before, DB::table('document_chunks')->count());
        $this->artisan('legal:chunk-documents', ['--limit' => 0])->assertFailed();
        $this->artisan('legal:chunk-documents', ['--force' => true, '--only-pending' => true])->assertFailed();
        self::assertSame($id, (int) DB::table('document_chunk_sets')->value('document_content_id'));
    }

    public function test_queue_is_isolated_and_duplicate_delivery_is_safe(): void
    {
        $id = $this->content();
        $this->artisan('legal:chunk-documents', ['--queue' => true])->assertSuccessful();
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(1, DB::table('ai_jobs')->count());
        $job = Queue::connection('ai_database')->pop('legal-chunking');
        $job->fire();
        $job->delete();
        $before = DB::table('document_chunks')->get()->toJson();
        (new ChunkLegalDocumentJob($id))->handle(app(ChunkingPipeline::class));
        self::assertSame($before, DB::table('document_chunks')->get()->toJson());
        $this->artisan('legal:chunk-documents', ['--queue' => true])->assertSuccessful();
        self::assertSame(0, DB::table('ai_jobs')->count());
    }

    public function test_queue_misconfiguration_cannot_write_parser_jobs(): void
    {
        $this->content();
        config(['queue.connections.ai_database.connection' => 'pgsql']);
        $this->artisan('legal:chunk-documents', ['--queue' => true])->assertFailed();
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('ai_jobs')->count());
    }

    public function test_bounded_ai_worker_processes_only_its_own_jobs(): void
    {
        $id = $this->content();
        $this->artisan('legal:chunk-documents', ['--queue' => true])->assertSuccessful();
        config(['ai.enabled' => true, 'database.default' => 'pgsql_ai']);
        (new AiDevelopmentServiceProvider($this->app))->boot();
        $this->artisan('legal:work', ['--max-jobs' => 1])->assertSuccessful();
        self::assertSame(0, DB::table('ai_jobs')->count());
        self::assertGreaterThan(0, DB::table('document_chunks')->where('document_content_id', $id)->count());
        self::assertSame(0, DB::table('jobs')->count());
        self::assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_main_connection_refused_before_any_query_or_write(): void
    {
        $this->content();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        config(['ai.connection' => 'pgsql']);
        try {
            Artisan::call('legal:chunk-documents', ['--force' => true]);
            self::fail('Main connection must be refused');
        } catch (\LogicException $e) {
            self::assertStringContainsString('isolated', $e->getMessage());
        }
        self::assertSame([], $queries);
        config(['ai.connection' => 'pgsql_ai', 'database.connections.pgsql_ai.database' => 'mening_fikrim_active']);
        try {
            Artisan::call('legal:chunk-documents', ['--queue' => true]);
            self::fail('Main database alias must be refused');
        } catch (\LogicException $e) {
            self::assertStringContainsString('safety refusal', $e->getMessage());
        }
        self::assertSame([], $queries);
    }

    public function test_actual_database_is_checked_even_when_configuration_claims_test(): void
    {
        $db = DB::connection('pgsql_ai');
        $db->setDatabaseName('forged');
        // The physical connection remains the dedicated test DB; configured names are insufficient.
        self::assertSame('mening_fikrim_active_test', app(AiDatabaseGuard::class)->connection()->selectOne('select current_database() as db')->db);
        config(['database.connections.pgsql_ai.url' => 'postgresql://invalid/main']);
        $this->expectException(\LogicException::class);
        app(AiDatabaseGuard::class)->connection();
    }

    public function test_failure_rolls_back_old_chunks_and_retry_succeeds(): void
    {
        $id = $this->content();
        $service = app(ChunkDocumentService::class);
        $service->process($id, 4000);
        $before = DB::table('document_chunks')->get()->toJson();
        DB::statement("CREATE FUNCTION pg_temp.reject_chunk() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'injected failure'; END $$");
        DB::statement('CREATE TRIGGER reject_chunk BEFORE INSERT ON document_chunks FOR EACH ROW EXECUTE FUNCTION pg_temp.reject_chunk()');
        $this->artisan('legal:chunk-documents', ['--force' => true])->assertFailed();
        self::assertSame($before, DB::table('document_chunks')->get()->toJson());
        DB::statement('DROP TRIGGER reject_chunk ON document_chunks');
        $this->artisan('legal:chunk-documents', ['--force' => true])->assertSuccessful();
        self::assertSame(1, DB::table('document_chunk_sets')->count());
    }
}
