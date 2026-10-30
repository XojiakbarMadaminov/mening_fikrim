<?php

namespace Tests\Feature;

use App\Jobs\ImportDocumentJob;
use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentIdentifier;
use App\Models\DocumentSource;
use App\Services\Documents\DocumentImporter;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class FoundationDatabaseTest extends TestCase
{
    use DatabaseMigrations;

    private function source(string $slug = 'lex'): DocumentSource
    {
        return DocumentSource::create(['name' => $slug, 'slug' => $slug, 'type' => 'scraper']);
    }

    private function rejected(callable $operation, string $state): void
    {
        try {
            $operation();
            $this->fail('Database constraint did not reject the write.');
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0]);
        }
    }

    public function test_real_postgresql_schema_has_exact_authorized_business_tables_and_jsonb_columns(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertStringEndsWith('_test', DB::selectOne('select current_database() as name')->name);
        $tables = Schema::getTableListing(schemaQualified: false);
        sort($tables);
        $expected = ['cache', 'cache_locks', 'document_contents', 'document_identifiers', 'document_sources', 'documents', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'scrape_runs', 'scrape_run_items', 'scrape_campaigns', 'scrape_campaign_checkpoints', 'source_archive_blobs', 'source_archive_observations'];
        sort($expected);
        $this->assertSame($expected, $tables);
        foreach ([['documents', 'metadata'], ['document_identifiers', 'metadata'], ['scrape_runs', 'checkpoint'], ['scrape_campaigns', 'state'], ['scrape_campaign_checkpoints', 'state'], ['source_archive_observations', 'context']] as [$table, $column]) {
            $this->assertSame('jsonb', Schema::getColumnType($table, $column));
        }
    }

    public function test_test_schema_rebuild_replaces_a_surviving_postgresql_trigger_function(): void
    {
        $this->assertSame('mening_fikrim_active_test', DB::selectOne('select current_database() as name')->name);
        // Laravel fresh drops tables; PostgreSQL functions can survive that operation.
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('scrape_run_items'));
        $this->assertNotNull(DB::selectOne("select proname from pg_proc where proname='check_scrape_run_ledger'"));
    }

    public function test_migrations_roll_back_and_reapply_on_postgresql(): void
    {
        $this->artisan('migrate:rollback', ['--step' => DB::table('migrations')->where('migration', '>=', '2026_10_28_000002_create_documents_table')->count()])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('documents'));
        $this->assertTrue(Schema::hasTable('jobs'));
        $this->artisan('migrate', ['--seed' => true])->assertSuccessful();
        $this->assertDatabaseCount('document_sources', 2);
        $this->assertTrue(Schema::hasTable('document_identifiers'));
    }

    public function test_source_seeding_is_idempotent_and_preserves_disabled_sources(): void
    {
        $source = $this->source();
        $source->update(['is_active' => false]);
        $this->seed();
        $this->seed();
        $this->assertDatabaseCount('document_sources', 2);
        $this->assertFalse($source->fresh()->is_active);
    }

    public function test_all_relationships_and_casts_work(): void
    {
        $source = $this->source();
        $result = app(DocumentImporter::class)->import($source->id, FixtureInputs::seed(), new DateTimeImmutable('2026-10-28T12:00:00+05:00'));
        $document = Document::findOrFail($result['document_id']);
        $run = $source->scrapeRuns()->create(['mode' => 'daily', 'started_at' => '2026-10-28T12:00:00+05:00']);
        $this->assertTrue($document->source->is($source));
        $this->assertTrue($source->documents->first()->is($document));
        $this->assertCount(4, $source->identifiers);
        $this->assertCount(4, $document->identifiers);
        $this->assertCount(3, $document->contents);
        $this->assertTrue($document->identifiers->first()->document->is($document));
        $this->assertTrue($document->identifiers->first()->source->is($source));
        $this->assertTrue($document->contents->first()->document->is($document));
        $this->assertTrue($run->source->is($source));
        $this->assertTrue($source->scrapeRuns->first()->is($run));
        $this->assertInstanceOf(CarbonImmutable::class, $document->effective_at);
        $this->assertIsArray($document->metadata);
    }

    public function test_one_source_identifier_cannot_belong_to_two_documents(): void
    {
        $source = $this->source();
        $a = $source->documents()->create(['external_id' => 'a', 'title' => 'A', 'status' => 'active']);
        $b = $source->documents()->create(['external_id' => 'b', 'title' => 'B', 'status' => 'active']);
        $a->identifiers()->create(['source_id' => $source->id, 'external_id' => 'opaque']);
        $this->rejected(fn () => $b->identifiers()->create(['source_id' => $source->id, 'external_id' => 'opaque']), '23505');
    }

    public function test_foreign_keys_reject_missing_and_cross_source_references(): void
    {
        $source = $this->source();
        $other = $this->source('other');
        $document = $source->documents()->create(['title' => 'A', 'status' => 'active']);
        $this->rejected(fn () => Document::create(['source_id' => 999999, 'title' => 'bad', 'status' => 'active']), '23503');
        $this->rejected(fn () => DocumentIdentifier::create(['source_id' => $other->id, 'document_id' => $document->id, 'external_id' => 'bad']), '23503');
        $this->rejected(fn () => DocumentContent::create(['document_id' => 999999, 'language' => 'en', 'title' => 'bad', 'content' => 'bad', 'content_hash' => str_repeat('a', 64)]), '23503');
        $this->rejected(fn () => $source->delete(), '23503');
    }

    public function test_content_language_unique_and_document_delete_cascades(): void
    {
        $source = $this->source();
        app(DocumentImporter::class)->import($source->id, FixtureInputs::seed(), new DateTimeImmutable('2026-10-28'));
        $content = DocumentContent::first();
        $this->rejected(fn () => $content->replicate()->save(), '23505');
        Document::first()->delete();
        $this->assertDatabaseCount('document_contents', 0);
        $this->assertDatabaseCount('document_identifiers', 0);
    }

    public function test_nullable_manual_anchors_and_business_status_checks(): void
    {
        $source = DocumentSource::create(['name' => 'Manual', 'slug' => 'manual', 'type' => 'manual']);
        $source->documents()->create(['title' => 'Manual A', 'status' => 'active']);
        $source->documents()->create(['title' => 'Manual B', 'status' => 'pending']);
        $this->assertDatabaseCount('documents', 2);
        $this->rejected(fn () => DB::table('documents')->insert(['source_id' => $source->id, 'title' => 'bad', 'status' => 'review']), '23514');
        $this->rejected(fn () => DB::table('document_sources')->insert(['name' => 'bad', 'slug' => 'bad', 'type' => 'unknown']), '23514');
    }

    public function test_strict_completion_requires_all_gates_and_zero_failures_even_for_direct_sql(): void
    {
        $source = $this->source();
        $run = $source->scrapeRuns()->create(['mode' => 'full', 'started_at' => '2026-10-28']);
        $this->assertFalse($run->canComplete());
        $this->rejected(fn () => $run->update(['status' => 'completed']), '23514');
        $run->refresh();
        $gates = array_fill_keys(['coverage_verified', 'discovery_finished', 'jobs_reconciled', 'languages_complete', 'quality_passed'], true);
        foreach (array_keys($gates) as $gate) {
            $run->checkpoint = array_replace($gates, [$gate => false]);
            $this->assertFalse($run->canComplete());
            $this->rejected(fn () => $run->update(['status' => 'completed']), '23514');
            $run->refresh();
        }
        $run->update(['checkpoint' => $gates, 'processed_count' => 1, 'failed_count' => 1]);
        $this->assertFalse($run->canComplete());
        $this->rejected(fn () => $run->update(['status' => 'completed']), '23514');
        $run->refresh()->update(['status' => 'completed_with_errors', 'finished_at' => '2026-10-28T13:00:00+05:00']);
        $this->assertSame('completed_with_errors', $run->fresh()->status);
        $run->update(['failed_count' => 0]);
        $this->assertTrue($run->canComplete());
        $run->update(['status' => 'completed']);
        $this->assertSame('completed', $run->fresh()->status);
    }

    public function test_database_queue_dispatch_is_after_commit_and_rollback_does_not_leave_a_job(): void
    {
        $source = $this->source();
        try {
            DB::transaction(function () use ($source) {
                ImportDocumentJob::dispatch($source->id, FixtureInputs::seed(), '2026-10-28T12:00:00+05:00');
                $this->assertDatabaseCount('jobs', 0);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertDatabaseCount('jobs', 0);
        ImportDocumentJob::dispatch($source->id, FixtureInputs::seed(), '2026-10-28T12:00:00+05:00');
        $this->assertSame(1, Queue::connection('database')->size('scraping'));
        $job = Queue::connection('database')->pop('scraping');
        $job->fire(); // Execute one offline fixture job, never start a queue worker.
        $job->delete();
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_contents', 3);
        $this->assertSame(0, Queue::connection('database')->size('scraping'));
    }

    public function test_queue_timing_configuration_and_no_live_schedules(): void
    {
        $this->assertSame('database', config('queue.default'));
        $this->assertSame('database', config('cache.default'));
        $this->assertGreaterThan(config('scraping.job_timeout'), config('queue.connections.database.retry_after'));
        $this->assertSame(2000, config('scraping.request_delay_ms'));
        $this->assertSame('Asia/Tashkent', config('app.timezone'));
        $this->assertSame([], app(Schedule::class)->events());
        $routes = app('router')->getRoutes()->getRoutes();
        $this->assertSame(['api/admin/corpus/campaigns', 'api/admin/corpus/campaigns/{id}/report', 'api/admin/corpus/campaigns/{id}',
            'api/admin/corpus/statistics', 'api/admin/corpus/quality', 'api/admin/corpus/discovery', 'api/admin/corpus/tools',
            'api/admin/corpus/tools/{tool}', 'api/admin/corpus/assistant', 'up'], array_map(fn ($route) => $route->uri(), $routes));
        foreach ($routes as $route) {
            if (str_starts_with($route->uri(), 'api/')) {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
                $this->assertContains('auth:corpus-reports', $route->middleware());
                $this->assertContains('can:corpus.read', $route->middleware());
            }
        }
        $job = new ImportDocumentJob(1, [], '2026-10-28');
        $this->assertSame(900, $job->timeout);
        $this->assertSame(3, $job->tries);
    }
}
