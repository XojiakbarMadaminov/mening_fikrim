<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSource;
use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexRequestException;
use App\Scraping\LexUz\LexUzScraper;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Fixtures\FixtureInputs;
use Tests\Fixtures\LexResponses;
use Tests\Fixtures\VirtualLexHttpClient;
use Tests\TestCase;

class ScrapingIntegrationTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        // Register the same failed-job persistence hook as queue:work around the real Worker engine.
        Queue::failing(function (JobFailed $event) {
            app('queue.failer')->log($event->connectionName, $event->job->getQueue(), $event->job->getRawBody(), $event->exception);
        });
    }

    private function source(): DocumentSource
    {
        return DocumentSource::create(['name' => 'Lex.uz', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
    }

    private function work(): void
    {
        app('queue.worker')->runNextJob('database', 'scraping', new WorkerOptions(backoff: 0, memory: 256, timeout: 900, sleep: 0, maxTries: 3, force: true));
    }

    public function test_http_parser_validator_importer_and_real_database_queue_work_together(): void
    {
        $client = new VirtualLexHttpClient;
        $this->app->instance(LexHttpClient::class, $client);
        Http::fake(array_map(fn ($html) => Http::response($html, 200, ['Content-Type' => 'text/html']), LexResponses::graph()));
        $manager = app(ScrapeRunManager::class);
        $run = $manager->start($this->source(), 'full', 1, ['8506802']);
        $this->work(); // real discovery job selects the explicit bounded sample
        $this->work(); // real import job fetches controlled HTTP responses and commits PostgreSQL data
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_identifiers', 4);
        $this->assertDatabaseCount('document_contents', 3);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertNotNull(Document::first()->last_checked_at);
        $this->assertStringStartsWith('# Strategik', Document::first()->contents()->where('language', 'uz-Latn')->first()->content);
        $this->assertSame('completed_with_errors', $run->fresh()->status);
        $this->assertTrue($run->fresh()->checkpoint['jobs_reconciled']);
        $this->assertFalse($run->fresh()->checkpoint['coverage_verified']);
        $this->assertSame(1, $manager->summary($run->fresh())['created']);
        $finished = $run->fresh()->finished_at;
        $this->travel(60)->seconds();
        $manager->reconcile($run->id);
        $manager->resume($run->id);
        $this->assertEquals($finished, $run->fresh()->finished_at);
        Http::assertSentCount(8);
    }

    public function test_aspnet_pagination_uses_current_hidden_fields_and_source_postback_target(): void
    {
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        $url = 'https://lex.uz/uz/search/nat?status=Y';
        Http::fake([$url => Http::sequence()
            ->push(LexResponses::listing(['8506802', '-8506802'], 3, 'ucFoundActsControl$LinkButton1', 'first-state'))
            ->push(LexResponses::listing(['8506870'], 3))]);
        $pages = [];
        $result = app(LexUzScraper::class)->discover(['section' => 'nat', 'status' => 'Y'], function ($page) use (&$pages) {
            $pages[] = $page;

            return true;
        }, 5);
        $this->assertTrue($result['complete']);
        $this->assertSame(3, $result['unique_ids']);
        $this->assertCount(2, $pages);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['__VIEWSTATE'] === 'first-state' && $request['EXTRA_STATE'] === 'extra-secret' && $request['__EVENTTARGET'] === 'ucFoundActsControl$LinkButton1');
    }

    public function test_invalid_expired_state_restarts_with_fresh_get_without_skipping_a_page(): void
    {
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        $url = 'https://lex.uz/uz/search/nat?status=Y';
        $first = LexResponses::listing(['8506802', '-8506802'], 3, 'ucFoundActsControl$LinkButton1');
        Http::fake([$url => Http::sequence()->push($first)->push('<html>invalid viewstate</html>')->push($first)->push(LexResponses::listing(['8506870'], 3))]);
        $ids = [];
        $result = app(LexUzScraper::class)->discover(['section' => 'nat', 'status' => 'Y'], function ($page) use (&$ids) {
            foreach ($page['rows'] as $row) {
                $ids[$row['id']] = true;
            }

            return true;
        }, 5);
        $this->assertSame(1, $result['restarts']);
        $this->assertCount(3, $ids);
        Http::assertSentCount(4);
    }

    public function test_checkpoint_and_queue_insertion_roll_back_together(): void
    {
        $manager = app(ScrapeRunManager::class);
        $run = $manager->start($this->source(), 'full', 2);
        DB::table('jobs')->delete(); // controlled loss before any discovery work was committed
        try {
            DB::transaction(function () use ($manager, $run) {
                $manager->selectPage($run->id, ['rows' => [['id' => '8506802', 'listing_status' => 'Y']], 'page' => 1, 'total' => 2, 'fingerprint' => 'test-page', 'last_page' => false]);
                $this->assertDatabaseCount('jobs', 1);
                throw new \RuntimeException('Controlled transaction failure.');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('Controlled transaction failure.', $e->getMessage());
        }
        $this->assertSame(0, $run->items()->count());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_resume_replays_from_fresh_session_and_deduplicates_committed_identifiers(): void
    {
        $manager = app(ScrapeRunManager::class);
        $run = $manager->start($this->source(), 'full', 3);
        $manager->selectPage($run->id, ['rows' => [['id' => '8506802', 'listing_status' => 'Y'], ['id' => '-8506802', 'listing_status' => 'Y']], 'page' => 1, 'total' => 3, 'fingerprint' => 'first', 'last_page' => false]);
        $this->assertDatabaseCount('jobs', 3); // one discovery + two imports
        $manager->resume($run->id);
        $this->assertDatabaseCount('jobs', 3); // no second copy while durable jobs are still present
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('discover')->once()->andReturnUsing(function ($segment, $commit) {
            $commit(['rows' => [['id' => '8506802', 'listing_status' => 'Y'], ['id' => '-8506802', 'listing_status' => 'Y']], 'page' => 1, 'total' => 3, 'fingerprint' => 'first', 'last_page' => false]);
            $commit(['rows' => [['id' => '8506870', 'listing_status' => 'Y']], 'page' => 2, 'total' => 3, 'fingerprint' => 'second', 'last_page' => true]);

            return ['complete' => true, 'pages' => 2, 'total' => 3, 'unique_ids' => 3];
        });
        $fake->shouldReceive('fetch')->once()->andReturnUsing(function ($id) {
            $input = FixtureInputs::seed();
            $input['entry_id'] = $id;

            return $input + ['diagnostics' => ['primary_format' => 'html', 'quality' => []]];
        });
        $this->app->instance(DocumentScraper::class, $fake);
        for ($i = 0; $i < 4; $i++) {
            $this->work();
        }
        $this->assertDatabaseCount('documents', 1);
        $this->assertSame(3, $run->fresh()->processed_count);
        $this->assertSame(1, $run->fresh()->created_count);
        $this->assertSame(2, $manager->summary($run->fresh())['skipped_alias']);
        $this->assertTrue($run->fresh()->checkpoint['jobs_reconciled']);
        $this->assertStringNotContainsString('__VIEWSTATE', json_encode($run->fresh()->checkpoint));
    }

    public function test_retry_then_success_is_tracked_by_the_real_database_queue(): void
    {
        $manager = app(ScrapeRunManager::class);
        $run = $manager->start($this->source(), 'daily', 1, ['8506802']);
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->once()->ordered()->andThrow(new LexRequestException('Controlled temporary failure.', 503));
        $fake->shouldReceive('fetch')->once()->ordered()->andReturn(FixtureInputs::seed() + ['diagnostics' => ['primary_format' => 'html', 'quality' => []]]);
        $this->app->instance(DocumentScraper::class, $fake);
        $this->work();
        $this->work(); // released with backoff
        $this->assertSame(1, DB::table('jobs')->value('attempts'));
        $this->assertSame(0, $run->fresh()->processed_count);
        DB::table('jobs')->update(['available_at' => time() - 1]); // controlled clock advance, not a real server event
        $this->work();
        $this->assertSame(1, $run->fresh()->processed_count);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame(1, $run->fresh()->created_count);
    }

    public function test_partial_language_review_keeps_valid_text_and_durable_review_and_never_completes(): void
    {
        $manager = app(ScrapeRunManager::class);
        $run = $manager->start($this->source(), 'full', 1, ['8506802']);
        $input = FixtureInputs::seed();
        unset($input['contents']['en']);
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->once()->andReturn($input + ['diagnostics' => ['primary_format' => 'html', 'quality' => []]]);
        $this->app->instance(DocumentScraper::class, $fake);
        $this->work();
        $this->work();
        $this->assertDatabaseCount('document_contents', 2);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertSame('review', $run->items()->first()->status);
        $this->assertSame(0, $run->fresh()->failed_count);
        $this->assertSame('completed_with_errors', $run->fresh()->status);
        $this->assertFalse($run->fresh()->checkpoint['languages_complete']);
        $this->assertNull(Document::first()->last_checked_at);
    }

    public function test_commands_require_explicit_bounded_limits_and_live_configuration(): void
    {
        $this->source();
        $this->artisan('documents:sync', ['source' => 'lex', '--mode' => 'full', '--limit' => 30])->assertFailed();
        config(['scraping.live_enabled' => true]);
        $this->artisan('documents:sync', ['source' => 'lex', '--mode' => 'full'])->assertFailed();
        $this->artisan('documents:sync', ['source' => 'lex', '--limit' => 1000])->assertFailed();
        foreach (['full', 'daily', 'recheck'] as $mode) {
            $this->artisan('documents:sync', ['source' => 'lex', '--mode' => $mode, '--limit' => 30])->assertSuccessful();
        }
        $this->assertDatabaseCount('scrape_runs', 3);
    }

    public function test_checkpoint_prefix_drift_fails_without_committing_new_ids_or_jobs(): void
    {
        $manager = app(ScrapeRunManager::class);
        $run = $manager->start($this->source(), 'full', 3);
        $page = ['rows' => [['id' => '8506802', 'listing_status' => 'Y']], 'page' => 1, 'total' => 3, 'fingerprint' => 'first', 'prefix_fingerprint' => 'stable-prefix', 'last_page' => false];
        $manager->selectPage($run->id, $page);
        $snapshot = $run->fresh()->checkpoint;
        $jobs = DB::table('jobs')->count();
        $page['rows'][0]['id'] = '8506870';
        $page['prefix_fingerprint'] = 'changed-prefix';
        try {
            $manager->selectPage($run->id, $page);
            $this->fail('Drifted replay must fail closed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('prefix changed', $e->getMessage());
        }
        $this->assertEquals($snapshot, $run->fresh()->checkpoint);
        $this->assertSame($jobs, DB::table('jobs')->count());
    }

    public function test_crash_after_limit_commit_finishes_discovery_without_skipping_pending_imports(): void
    {
        $manager = app(ScrapeRunManager::class);
        $run = $manager->start($this->source(), 'full', 1);
        $manager->selectPage($run->id, ['rows' => [['id' => '8506802', 'listing_status' => 'Y']], 'page' => 1, 'total' => 2, 'fingerprint' => 'first', 'last_page' => false]);
        $this->work(); // resumed discovery sees the committed limit and records the bounded stop
        $this->assertTrue($run->fresh()->checkpoint['discovery_finished']);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertFalse($run->fresh()->checkpoint['jobs_reconciled']);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_recheck_selects_due_and_pending_documents_but_preserves_fresh_and_inactive_rows(): void
    {
        $source = $this->source();
        foreach ([['old', 'active', now()->subDays(91), null], ['fresh', 'active', now(), null], ['due', 'pending', now(), now()->toDateString()], ['future', 'pending', now(), now()->addMonth()->toDateString()], ['revoked', 'inactive', null, null]] as [$id, $status, $checked, $effective]) {
            $source->documents()->create(['external_id' => $id, 'title' => $id, 'status' => $status, 'last_checked_at' => $checked, 'effective_at' => $effective]);
        }
        $run = app(ScrapeRunManager::class)->start($source, 'recheck', 30);
        $this->work();
        $ids = $run->items()->pluck('source_external_id')->all();
        sort($ids);
        $this->assertSame(['due', 'old'], $ids);
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_optional_bounded_section_uses_only_a_verified_discovery_root(): void
    {
        $manager = app(ScrapeRunManager::class);
        $source = $this->source();
        $run = $manager->start($source, 'full', 1, section: 'int');
        $this->assertSame([['section' => 'int', 'status' => 'Y']], $run->checkpoint['segments']);
        $this->expectException(\InvalidArgumentException::class);
        $manager->start($source, 'full', 1, section: 'unique');
    }
}
