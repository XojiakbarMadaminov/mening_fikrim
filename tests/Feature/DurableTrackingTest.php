<?php

namespace Tests\Feature;

use App\Jobs\ImportDocumentJob;
use App\Models\DocumentSource;
use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexRequestException;
use App\Services\Scraping\RunItemTracker;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class DurableTrackingTest extends TestCase
{
    use DatabaseMigrations;

    private function createRun(array $ids = ['8506802'])
    {
        $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $run = app(ScrapeRunManager::class)->start($source, 'full', count($ids), $ids);
        $this->work();

        return $run;
    }

    private function work(): void
    {
        app('queue.worker')->runNextJob('database', 'scraping', new WorkerOptions(backoff: 0, timeout: 900, sleep: 0, maxTries: 3, force: true));
    }

    private function candidate(): array
    {
        return FixtureInputs::seed() + ['diagnostics' => ['primary_format' => 'html', 'quality' => []]];
    }

    public function test_unique_opaque_item_ids_and_foreign_keys_are_enforced(): void
    {
        $run = $this->createRun(['8506802', '-8506802']);
        $this->assertSame(['-8506802', '8506802'], $run->items()->orderBy('source_external_id')->pluck('source_external_id')->all());
        $this->expectException(QueryException::class);
        DB::transaction(fn () => $run->items()->create(['source_external_id' => '8506802']));
    }

    public function test_item_document_foreign_key_rejects_a_missing_document(): void
    {
        $run = $this->createRun();
        $this->expectException(QueryException::class);
        DB::transaction(fn () => $run->items()->first()->update(['document_id' => 987654321]));
    }

    public function test_terminal_item_cannot_silently_return_to_processing(): void
    {
        $item = $this->createRun()->items()->first();
        $tracker = app(RunItemTracker::class);
        $tracker->claim($item->id, $item->dispatch_token, null);
        $tracker->finish($item->scrape_run_id, $item->source_external_id, ['state' => 'review', 'complete' => false]);
        $this->expectException(\LogicException::class);
        $item->fresh()->moveTo('processing');
    }

    public function test_duplicate_queue_delivery_uses_one_item_and_one_attempt(): void
    {
        $run = $this->createRun();
        $item = $run->items()->first();
        app(ScrapeRunManager::class)->push(new ImportDocumentJob($run->source_id, $item->source_external_id, null, $run->id, null, $item->id, $item->dispatch_token));
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->once()->andReturn($this->candidate());
        $this->app->instance(DocumentScraper::class, $fake);
        $this->work();
        $this->work();
        $this->assertDatabaseCount('scrape_run_items', 1);
        $this->assertSame(1, $item->fresh()->attempts);
        $this->assertSame(1, $run->fresh()->processed_count);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_quality_review_is_durable_normal_success_without_technical_failure(): void
    {
        $run = $this->createRun();
        $input = $this->candidate();
        unset($input['contents']['en']);
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->once()->andReturn($input);
        $this->app->instance(DocumentScraper::class, $fake);
        $this->work();
        $item = $run->items()->first();
        $this->assertSame('review', $item->status);
        $this->assertNotEmpty($item->review_reasons['languages']['en']['errors']);
        $this->assertSame(1, $item->attempts);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(0, $run->fresh()->failed_count);
        $this->assertSame(1, app(ScrapeRunManager::class)->summary($run->fresh())['review_required']);
        app(ScrapeRunManager::class)->resume($run->id);
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_unexpected_parser_exception_retries_then_exhausts_as_technical_failure(): void
    {
        Queue::failing(fn (JobFailed $event) => app('queue.failer')->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception));
        $run = $this->createRun();
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->times(3)->andThrow(new \RuntimeException('Controlled unexpected parser bug'));
        $this->app->instance(DocumentScraper::class, $fake);
        for ($i = 0; $i < 3; $i++) {
            DB::table('jobs')->update(['available_at' => time() - 1]);
            $this->work();
        }
        $item = $run->items()->first();
        $this->assertSame('failed', $item->status);
        $this->assertSame(3, $item->attempts);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertSame(1, $run->fresh()->failed_count);
        $this->assertSame(1, $run->fresh()->processed_count);
        $this->assertSame(0, app(ScrapeRunManager::class)->summary($run->fresh())['review_required']);
    }

    public function test_resume_does_not_duplicate_a_delayed_database_queue_retry_receipt(): void
    {
        $run = $this->createRun();
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->once()->andThrow(new LexRequestException('Controlled retry', 503));
        $this->app->instance(DocumentScraper::class, $fake);
        $this->work();
        $this->assertSame('retry_wait', $run->items()->first()->status);
        $receipt = DB::table('jobs')->value('id');
        app(ScrapeRunManager::class)->resume($run->id);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame($receipt, DB::table('jobs')->value('id'));
    }

    public function test_expired_missing_processing_is_requeued_and_old_dispatch_is_fenced(): void
    {
        $run = $this->createRun();
        $item = $run->items()->first();
        $tracker = app(RunItemTracker::class);
        $oldToken = $item->dispatch_token;
        $tracker->claim($item->id, $oldToken, null);
        DB::table('jobs')->delete(); // controlled crash after claim, before any import commit
        $item->fresh()->update(['lease_expires_at' => now()->subSecond()]);
        app(ScrapeRunManager::class)->resume($run->id);
        $this->assertSame('queued', $item->fresh()->status);
        $this->assertNotSame($oldToken, $item->fresh()->dispatch_token);
        $this->assertNull($tracker->claim($item->id, $oldToken, null)['item']);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_active_processing_is_never_reclaimed_early(): void
    {
        $run = $this->createRun();
        $item = $run->items()->first();
        app(RunItemTracker::class)->claim($item->id, $item->dispatch_token, null);
        DB::table('jobs')->delete();
        app(ScrapeRunManager::class)->resume($run->id);
        $this->assertSame('processing', $item->fresh()->status);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('running', $run->fresh()->status);
    }

    public function test_import_and_terminal_outcome_rollback_together_at_controlled_crash_boundary(): void
    {
        $run = $this->createRun();
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->once()->andReturn($this->candidate());
        $this->app->instance(DocumentScraper::class, $fake);
        $real = app(RunItemTracker::class);
        $mock = Mockery::mock(RunItemTracker::class)->makePartial();
        $mock->shouldReceive('claim')->andReturnUsing(fn (...$args) => $real->claim(...$args));
        $mock->shouldReceive('confirmedAlias')->andReturn(null);
        $mock->shouldReceive('finish')->once()->andThrow(new \RuntimeException('Controlled failure before terminal outcome commit'));
        $this->app->instance(RunItemTracker::class, $mock);
        // Rebind manager so its tracker participates in the fault injection too.
        $this->app->forgetInstance(ScrapeRunManager::class);
        $this->work();
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_contents', 0);
        $this->assertSame('retry_wait', $run->items()->first()->status);
        $this->assertSame(0, $run->fresh()->processed_count);
    }

    public function test_partial_technical_failure_preserves_valid_languages_and_created_counter_on_retry(): void
    {
        $run = $this->createRun();
        $input = $this->candidate();
        unset($input['contents']['en']);
        $input['technical_exception'] = new \RuntimeException('Controlled per-language process failure');
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->once()->ordered()->andReturnUsing(function () use ($input) {
            $this->assertSame(0, DB::transactionLevel(), 'Network/parsing must run outside importer transactions.');

            return $input;
        });
        $fake->shouldReceive('fetch')->once()->ordered()->andReturn($this->candidate());
        $this->app->instance(DocumentScraper::class, $fake);
        $this->work();
        $this->assertDatabaseCount('document_contents', 2);
        $this->assertSame('retry_wait', $run->items()->first()->status);
        $this->assertSame('created', $run->items()->first()->action);
        DB::table('jobs')->update(['available_at' => time() - 1]);
        $this->work();
        $this->assertDatabaseCount('document_contents', 3);
        $this->assertSame(1, $run->fresh()->created_count);
        $this->assertSame(0, $run->fresh()->updated_count);
        $this->assertSame(1, $run->fresh()->processed_count);
        $this->assertSame(2, $run->items()->first()->attempts);
    }

    public function test_sql_cannot_complete_with_a_review_item_even_if_checkpoint_flags_are_forged(): void
    {
        $run = $this->createRun();
        $item = $run->items()->first();
        app(RunItemTracker::class)->claim($item->id, $item->dispatch_token, null);
        app(RunItemTracker::class)->finish($run->id, $item->source_external_id, ['state' => 'review', 'complete' => false]);
        $this->expectException(\PDOException::class);
        DB::transaction(function () use ($run) {
            $cp = array_replace($run->checkpoint, array_fill_keys(['coverage_verified', 'discovery_finished', 'jobs_reconciled', 'languages_complete', 'quality_passed'], true));
            $run->update(['checkpoint' => $cp, 'status' => 'completed']);
        });
    }

    public function test_explicit_initial_inactive_collection_records_source_status_and_verified_identity(): void
    {
        $run = $this->createRun();
        $input = $this->candidate();
        $input['status_evidence']['state'] = 'revoked';
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->once()->andReturn($input);
        $this->app->instance(DocumentScraper::class, $fake);
        $this->work();
        $item = $run->items()->first();
        $this->assertSame('imported', $item->status);
        $this->assertSame('inactive', $item->outcome['source_status']);
        $this->assertTrue($item->outcome['identity_verified']);
        $this->assertSame(1, app(ScrapeRunManager::class)->summary($run->fresh())['canonical_document_groups']);
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_read_only_item_cli_does_not_dispatch_jobs(): void
    {
        $run = $this->createRun();
        $jobs = DB::table('jobs')->count();
        $this->artisan('documents:sync', ['--items' => $run->id, '--status' => 'queued'])->expectsOutputToContain('8506802')->assertSuccessful();
        $this->assertSame($jobs, DB::table('jobs')->count());
    }
}
