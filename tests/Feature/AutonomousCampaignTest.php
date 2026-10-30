<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentSource;
use App\Models\ScrapeCampaign;
use App\Scraping\Contracts\DocumentScraper;
use App\Services\Scraping\AutonomousCampaign;
use App\Services\Scraping\AutonomousCampaignWorker;
use App\Services\Scraping\BoundedDiscovery;
use App\Services\Scraping\CampaignSessionMutex;
use App\Services\Scraping\DurableCampaign;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class AutonomousCampaignTest extends TestCase
{
    use DatabaseMigrations;

    private ?AutonomousCampaign $controller = null;

    private ?AutonomousCampaignWorker $worker = null;

    protected function tearDown(): void
    {
        $this->controller?->close();
        $this->worker?->close();
        app(DurableCampaign::class)->release();
        parent::tearDown();
    }

    private function prepare(int $target = 2): array
    {
        DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $campaign = app(DurableCampaign::class);
        $campaign->create('p6-base', 20, 20, 1);
        $campaign->stop('bounded_discovery_scope_exhausted');
        $campaign->release();
        $previous = ScrapeCampaign::first()->getRawOriginal();
        config(['corpus_campaigns.p61-test' => ['predecessor' => 'p6-base', 'requests' => 30, 'sources' => 10, 'target' => $target,
            'stages' => $target === 1 ? [1] : [1, 2], 'scope' => [['section' => 'nat', 'status' => 'Y', 'from' => '01.03.2020', 'to' => '02.03.2020']]]]);
        $discovery = Mockery::mock(BoundedDiscovery::class);
        $discovery->shouldReceive('collect')->zeroOrMoreTimes()->andReturn(['complete' => true, 'rows' => [['id' => '1001', 'listing_status' => 'Y'], ['id' => '1002', 'listing_status' => 'Y']]]);
        $this->app->instance(BoundedDiscovery::class, $discovery);
        $scraper = Mockery::mock(DocumentScraper::class);
        $scraper->shouldReceive('fetch')->zeroOrMoreTimes()->andReturnUsing(fn ($id) => $this->input($id));
        $this->app->instance(DocumentScraper::class, $scraper);
        $this->controller = app(AutonomousCampaign::class);
        $this->controller->prepare('p61-test');
        $this->worker = app(AutonomousCampaignWorker::class);
        $this->worker->begin();
        $this->assertSame($previous, ScrapeCampaign::first()->getRawOriginal());

        return $previous;
    }

    private function input(string $id): array
    {
        $input = FixtureInputs::seed();
        $node = collect($input['identity'])->first(fn ($node) => $node['availability'] === 'full_text');
        $language = $node['language'];
        $node['external_id'] = $id;
        $node['links'] = [$id];
        $node['source_url'] = 'https://lex.uz/uz/docs/'.$id;
        $input['entry_id'] = $id;
        $input['source_url'] = $node['source_url'];
        $input['identity'] = [$node];
        $input['contents'] = [$language => $input['contents'][$language]];
        $input['contents'][$language]['quality']['issues'] = ['complex_table_semantics_review'];
        $input['contents'][$language]['quality']['structure_verified'] = false;
        $input['diagnostics'] = ['primary_format' => 'html', 'quality' => [$language => $input['contents'][$language]['quality']]];

        return $input;
    }

    public function test_preparation_is_idempotent_paused_and_has_no_http_or_jobs(): void
    {
        $this->prepare();
        $this->controller->prepare('p61-test');
        $this->assertDatabaseCount('scrape_campaigns', 2);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame('paused', app(DurableCampaign::class)->state()['status']);
        $this->assertSame(0, app(DurableCampaign::class)->state()['used']);
        Http::assertNothingSent();
    }

    public function test_two_actors_automatically_discover_dispatch_advance_and_persist_final_report(): void
    {
        $old = $this->prepare();
        $this->controller->begin('p61-test');
        $running = true;
        for ($i = 0; $i < 30 && $running; $i++) {
            $running = $this->controller->tick();
            if ($running) {
                $this->worker->tick('p61-test');
            }
        }
        $this->assertFalse($running, 'Autonomous target should complete without manually advancing stages.');
        $this->assertSame('completed', app(DurableCampaign::class)->state()['status']);
        $this->assertSame(2, Document::count());
        $this->assertSame(2, DocumentContent::where('quality_status', 'needs_review')->count());
        $this->assertSame($old, ScrapeCampaign::first()->getRawOriginal());
        $this->assertDatabaseHas('scrape_campaign_checkpoints', ['name' => 'stage-1']);
        $this->assertDatabaseHas('scrape_campaign_checkpoints', ['name' => 'stage-2']);
        $this->assertDatabaseHas('scrape_campaign_checkpoints', ['name' => 'final-report']);
        $this->assertDatabaseCount('failed_jobs', 0);
        Http::assertNothingSent();
    }

    public function test_empty_queue_does_not_complete_before_discovery(): void
    {
        $this->prepare();
        $this->controller->begin('p61-test');
        $this->assertTrue($this->controller->tick());
        $state = app(DurableCampaign::class)->state();
        $this->assertSame('running', $state['status']);
        $this->assertSame(1, $state['control']['scope_index']);
        $this->assertSame(0, $state['new_canonical_documents']);
        $this->assertTrue($this->controller->tick());
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_signal_pause_and_restart_preserve_receipts_and_stage(): void
    {
        $this->prepare();
        $this->controller->begin('p61-test');
        $this->controller->tick();
        $this->controller->tick();
        $payloads = DB::table('jobs')->pluck('payload', 'id')->all();
        $this->controller->requestShutdown(); // Same safe-boundary path used by SIGINT/SIGTERM traps.
        $this->assertFalse($this->controller->tick());
        $this->assertSame('paused', app(DurableCampaign::class)->state()['status']);
        $this->controller->close();
        $this->controller = app(AutonomousCampaign::class);
        $this->assertSame(0, Artisan::call('documents:campaign', ['action' => 'resume', '--id' => 'p61-test']));
        $this->controller->begin('p61-test');
        $this->controller->tick();
        $this->assertSame($payloads, DB::table('jobs')->pluck('payload', 'id')->all());
        $this->worker->tick('p61-test');
        $this->worker->tick('p61-test');
        $this->assertSame(1, Document::count());
    }

    public function test_reserved_worker_receipt_is_not_stolen_or_dispatched_twice(): void
    {
        $this->prepare();
        $this->controller->begin('p61-test');
        $this->controller->tick();
        $this->controller->tick();
        $this->worker->tick('p61-test'); // explicit-selection job -> import receipt
        DB::table('jobs')->update(['reserved_at' => now()->timestamp, 'attempts' => 1]);
        $before = DB::table('jobs')->get()->toJson();
        $this->controller->tick();
        $this->worker->tick('p61-test');
        $this->assertSame($before, DB::table('jobs')->get()->toJson());
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_operator_stop_is_terminal_and_cannot_resume(): void
    {
        $this->prepare();
        $this->assertSame(0, Artisan::call('documents:campaign', ['action' => 'stop', '--id' => 'p61-test']));
        $this->assertDatabaseHas('scrape_campaign_checkpoints', ['name' => 'final-report']);
        $this->assertSame('stopped', app(DurableCampaign::class)->state()['status']);
        $this->assertSame(1, Artisan::call('documents:campaign', ['action' => 'resume', '--id' => 'p61-test']));
        $this->assertSame(0, app(DurableCampaign::class)->state()['used']);
    }

    public function test_controller_and_worker_coordination_mutexes_reject_duplicate_instances(): void
    {
        $this->prepare();
        $this->controller->begin('p61-test');
        foreach ([2, 3] as $key) {
            $duplicate = new CampaignSessionMutex($key);
            try {
                $duplicate->acquire();
                $this->fail('Duplicate actor must be rejected.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('already active', $e->getMessage());
            }
        }
    }

    public function test_first_operator_start_begins_window_but_later_resume_does_not_reset_it(): void
    {
        $this->prepare();
        $preparedAt = ScrapeCampaign::latest('id')->first()->started_at;
        $this->travel(1)->days();
        $this->controller->begin('p61-test');
        $started = ScrapeCampaign::latest('id')->first()->started_at;
        $this->assertGreaterThan($preparedAt, $started);
        app(DurableCampaign::class)->requestPause('p61-test');
        $this->controller->tick();
        $this->controller->close();
        $this->assertSame(0, Artisan::call('documents:campaign', ['action' => 'resume', '--id' => 'p61-test']));
        $this->assertEquals($started, ScrapeCampaign::latest('id')->first()->started_at);
    }

    public function test_cli_dry_run_never_creates_a_campaign_or_calls_source(): void
    {
        $this->assertSame(0, Artisan::call('documents:campaign', ['action' => 'run', '--id' => 'p6-1-completion-150-20261029', '--dry-run' => true]));
        $this->assertDatabaseCount('scrape_campaigns', 0);
        Http::assertNothingSent();
    }

    public function test_real_sigterm_pauses_controller_and_restart_keeps_committed_work(): void
    {
        $this->prepare();
        $campaign = app(DurableCampaign::class);
        $campaign->claim('p61-test');
        $campaign->saveControl(['phase' => 'discover', 'scope_index' => 1, 'pool' => [['id' => '1001', 'listing_status' => 'Y']], 'run_id' => null]);
        $campaign->release();
        $process = new Process([PHP_BINARY, base_path('tests/Support/p61_signal_controller.php'), 'p61-test'], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'mening_fikrim_active_test', 'DB_URL' => '']);
        $process->setTimeout(15);
        try {
            $process->start();
            $deadline = microtime(true) + 8;
            while (DB::table('jobs')->count() === 0 && $process->isRunning() && microtime(true) < $deadline) {
                usleep(50000);
            }
            $this->assertDatabaseCount('jobs', 1);
            $receipts = DB::table('jobs')->pluck('payload', 'id')->all();
            $process->signal(SIGTERM);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
            $this->assertSame('paused', $campaign->state()['status']);
            $this->assertSame($receipts, DB::table('jobs')->pluck('payload', 'id')->all());
            $this->assertSame(0, $campaign->state()['used']);
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    public function test_new_campaign_cannot_bypass_an_access_denial_predecessor(): void
    {
        $this->prepare();
        $campaign = app(DurableCampaign::class);
        $campaign->claim('p61-test');
        $campaign->stop('source_http_403');
        $campaign->release();
        config(['corpus_campaigns.p61-other' => ['predecessor' => 'p61-test', 'requests' => 30, 'sources' => 10, 'target' => 1, 'stages' => [1],
            'scope' => config('corpus_campaigns.p61-test.scope')]]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('do not bypass');
        app(AutonomousCampaign::class)->prepare('p61-other');
    }

    public function test_real_two_terminal_processes_complete_without_parent_stage_intervention(): void
    {
        $this->prepare();
        $this->worker->close();
        $campaign = app(DurableCampaign::class);
        $campaign->claim('p61-test');
        $campaign->saveControl(['phase' => 'discover', 'scope_index' => 1, 'pool' => [['id' => '1001', 'listing_status' => 'Y'], ['id' => '1002', 'listing_status' => 'Y']], 'run_id' => null]);
        $campaign->release();
        $env = ['APP_ENV' => 'testing', 'DB_DATABASE' => 'mening_fikrim_active_test', 'DB_URL' => ''];
        $controller = new Process([PHP_BINARY, base_path('tests/Support/p61_signal_controller.php'), 'p61-test'], base_path(), $env);
        $worker = new Process([PHP_BINARY, base_path('tests/Support/p61_worker.php'), 'p61-test'], base_path(), $env);
        try {
            $controller->setTimeout(30)->start();
            $worker->setTimeout(30)->start();
            $controller->wait();
            $worker->wait();
            $this->assertSame(0, $controller->getExitCode(), $controller->getOutput().$controller->getErrorOutput());
            $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertSame('completed', $campaign->state()['status']);
            $this->assertSame(2, $campaign->newCount());
            $this->assertDatabaseCount('jobs', 0);
            $this->assertDatabaseCount('documents', 2);
            $this->assertDatabaseHas('scrape_campaign_checkpoints', ['name' => 'final-report']);
        } finally {
            foreach ([$controller, $worker] as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
        }
    }

    public function test_sigkill_worker_releases_coordination_lock_without_refunding_budget(): void
    {
        $this->prepare();
        $this->worker->close();
        $this->controller->begin('p61-test');
        $campaign = app(DurableCampaign::class);
        $campaign->claim('p61-test');
        $campaign->reserve('crash-before-send');
        $campaign->release();
        $worker = new Process([PHP_BINARY, base_path('tests/Support/p61_worker.php'), 'p61-test'], base_path(), ['APP_ENV' => 'testing', 'DB_DATABASE' => 'mening_fikrim_active_test', 'DB_URL' => '']);
        try {
            $worker->start();
            $deadline = microtime(true) + 5;
            do {
                $held = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND classid=1369487366::oid AND objid=3::oid AND granted) AS held")->held;
                usleep(50000);
            } while (! $held && $worker->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($held);
            $worker->signal(SIGKILL);
            $worker->wait();
            $this->worker = app(AutonomousCampaignWorker::class);
            $this->worker->begin();
            $this->assertSame(1, $campaign->state()['used']);
            $this->assertSame(0, $campaign->newCount());
            $this->assertDatabaseCount('scrape_campaigns', 2);
        } finally {
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }

    public function test_terminal_report_recovers_a_dead_owner_without_reopening_campaign(): void
    {
        $this->prepare();
        ScrapeCampaign::where('campaign_id', 'p61-test')->update(['status' => 'stopped', 'stop_reason' => 'source_http_403',
            'owner_token' => '12345678-1234-1234-1234-123456789012', 'owner_backend_pid' => 2147483647, 'stopped_at' => now()]);
        $this->controller->begin('p61-test');
        $this->assertFalse($this->controller->tick());
        $this->assertDatabaseHas('scrape_campaign_checkpoints', ['name' => 'final-report']);
        $this->assertSame('stopped', app(DurableCampaign::class)->state()['status']);
        $this->assertSame(0, app(DurableCampaign::class)->state()['used']);
    }
}
