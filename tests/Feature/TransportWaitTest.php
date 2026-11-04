<?php

namespace Tests\Feature;

use App\Models\DocumentSource;
use App\Models\ScrapeCampaign;
use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexHttpClient;
use App\Services\Reporting\CampaignReportingService;
use App\Services\Scraping\AutonomousCampaign;
use App\Services\Scraping\AutonomousCampaignWorker;
use App\Services\Scraping\CampaignIntegrity;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\PilotStoppedException;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Fixtures\FixtureInputs;
use Tests\Fixtures\VirtualLexHttpClient;
use Tests\TestCase;

class TransportWaitTest extends TestCase
{
    use DatabaseMigrations;

    private const ID = 'corpus-30000-20261030';

    private const URL = 'https://lex.uz/uz/docs/8506802';

    protected function tearDown(): void
    {
        app(DurableCampaign::class)->release();
        foreach (ScrapeCampaign::where('campaign_type', 'corpus_collection')->get() as $row) {
            $row->checkpoints()->delete();
            $row->delete();
        }
        parent::tearDown();
    }

    private function prepared(): DurableCampaign
    {
        DocumentSource::create(['slug' => 'lex', 'name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);
        app(AutonomousCampaign::class)->prepare(self::ID);
        $campaign = app(DurableCampaign::class);
        $campaign->enableTransportWait(self::ID);
        $campaign->claim(self::ID);
        $campaign->resume();
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);

        return $campaign;
    }

    private function outage(DurableCampaign $campaign): void
    {
        for ($i = 0; $i < 2; $i++) {
            $campaign->reserve('synthetic-outage', 'GET', self::URL);
            $campaign->observe(0);
        }
    }

    public function test_two_transport_errors_wait_without_terminal_stop_and_restart_preserves_cooldown(): void
    {
        $campaign = $this->prepared();
        $this->outage($campaign);
        $state = $campaign->state();
        $this->assertSame('running', $state['status']);
        $this->assertTrue($state['transport_wait']['active']);
        $this->assertSame(2, $state['used']);
        $this->assertSame(1, $state['transport_wait']['episodes']);
        $campaign->release();
        $campaign = app(DurableCampaign::class);
        $campaign->claim(self::ID);
        $this->assertTrue($campaign->serviceTransportWait());
        $this->assertSame($state['transport_wait'], $campaign->state()['transport_wait']);
        try {
            $campaign->reserve('forbidden-while-waiting', 'GET', self::URL);
            $this->fail('Normal HTTP cannot pass the circuit.');
        } catch (PilotStoppedException $e) {
            $this->assertStringContainsString('transport_cooldown', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_controller_probes_once_then_worker_can_resume_original_receipts(): void
    {
        $campaign = $this->prepared();
        $run = app(ScrapeRunManager::class)->start(DocumentSource::first(), 'full', 1, ['8506802']);
        $campaign->saveControl(['phase' => 'wait', 'scope_index' => 0, 'pool' => [], 'run_id' => $run->id]);
        $jobs = DB::table('jobs')->get()->toJson();
        $this->outage($campaign);
        $campaign->release();
        $worker = app(AutonomousCampaignWorker::class);
        $worker->begin();
        try {
            $this->assertTrue($worker->tick(self::ID));
            $this->assertSame($jobs, DB::table('jobs')->get()->toJson());
            $controller = app(AutonomousCampaign::class);
            $controller->begin(self::ID);
            try {
                $this->assertTrue($controller->tick());
                Http::assertNothingSent();
                $this->travel(16)->minutes();
                Http::fake([self::URL => Http::response('synthetic transport reachable', 200)]);
                $this->assertTrue($controller->tick());
                $this->assertFalse($campaign->state()['transport_wait']['active']);
                $this->assertSame(3, $campaign->state()['used']);
                $this->assertSame($jobs, DB::table('jobs')->get()->toJson());
                Http::assertSentCount(1);
                $this->assertTrue($worker->tick(self::ID)); // Original discovery receipt, not duplicate dispatch.
                $this->assertDatabaseCount('scrape_run_items', 1);
            } finally {
                $controller->close();
            }
        } finally {
            $worker->close();
        }
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_failed_probes_use_one_request_and_persist_backoff_then_stop_at_eight(): void
    {
        $campaign = $this->prepared();
        $this->outage($campaign);
        $calls = 0;
        Http::fake(function () use (&$calls) {
            $calls++;
            throw new ConnectionException('synthetic outage');
        });
        for ($i = 1; $i <= 8; $i++) {
            $this->travel(61)->minutes();
            $campaign->serviceTransportWait();
            $state = $campaign->state();
            $this->assertSame(2 + $i, $state['used']);
            $this->assertSame($i, $state['transport_wait']['probe_attempts']);
            if ($i < 8) {
                $this->assertSame('running', $state['status']);
                $delay = Carbon::parse($state['transport_wait']['next_probe_at'])->diffInSeconds(now(), true);
                $this->assertEqualsWithDelta($i === 1 ? 1800 : 3600, $delay, 2);
                $campaign->serviceTransportWait();
                $this->assertSame(2 + $i, $campaign->state()['used']);
            }
        }
        $this->assertSame('transport_probe_budget_exhausted', $campaign->state()['stopped']['reason']);
        $this->assertSame(8, $calls, 'Connection exceptions are not recorded by Laravel assertSentCount.');
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_access_denial_during_probe_remains_terminal(): void
    {
        $campaign = $this->prepared();
        $this->outage($campaign);
        $this->travel(16)->minutes();
        Http::fake([self::URL => Http::response('denied', 429)]);
        try {
            $campaign->serviceTransportWait();
            $this->fail('429 cannot be bypassed.');
        } catch (PilotStoppedException $e) {
            $this->assertSame('source_http_429', $campaign->state()['stopped']['reason']);
        }
        Http::assertSentCount(1);
    }

    public function test_repeated_5xx_are_still_terminal_and_not_converted_to_transport_wait(): void
    {
        $campaign = $this->prepared();
        $campaign->reserve('server-error', 'GET', self::URL);
        $campaign->observe(503);
        $campaign->reserve('server-error', 'GET', self::URL);
        $campaign->observe(503);
        $this->assertSame('source_http_503_repeated', $campaign->state()['stopped']['reason']);
        $this->assertFalse($campaign->state()['transport_wait']['active']);
    }

    public function test_http_budget_and_integrity_are_enforced_before_probing(): void
    {
        $campaign = $this->prepared();
        $this->outage($campaign);
        $this->travel(16)->minutes();
        ScrapeCampaign::first()->update(['used_http_requests' => 360000]);
        try {
            $campaign->serviceTransportWait();
            $this->fail('Budget must not be exceeded.');
        } catch (PilotStoppedException) {
            $this->assertSame('campaign_request_budget_exhausted', $campaign->state()['stopped']['reason']);
        }
        Http::assertNothingSent();
    }

    public function test_integrity_failure_cannot_be_bypassed(): void
    {
        $campaign = $this->prepared();
        $this->outage($campaign);
        $this->travel(16)->minutes();
        $integrity = Mockery::mock(CampaignIntegrity::class);
        $integrity->shouldReceive('audit')->once()->andReturn(['errors' => ['invalid_content:42']]);
        $this->app->instance(CampaignIntegrity::class, $integrity);
        try {
            $campaign->serviceTransportWait();
            $this->fail('Audit error must stop.');
        } catch (PilotStoppedException) {
            $this->assertSame('stage_integrity_audit_failed', $campaign->state()['stopped']['reason']);
        }
        Http::assertNothingSent();
    }

    public function test_episode_and_original_deadline_caps_remain_terminal(): void
    {
        $campaign = $this->prepared();
        $row = ScrapeCampaign::first();
        $state = $row->state;
        $state['transport_wait']['episodes'] = 12; // Synthetic exhausted history, isolated test DB only.
        $row->update(['state' => $state]);
        $this->outage($campaign);
        $this->assertSame('transport_episode_budget_exhausted_or_no_probe_path', $campaign->state()['stopped']['reason']);
        Http::assertNothingSent();
    }

    public function test_deadline_is_enforced_while_waiting_without_source_traffic(): void
    {
        $campaign = $this->prepared();
        $this->outage($campaign);
        $this->travel(22)->days();
        try {
            $campaign->serviceTransportWait();
            $this->fail('Original deadline still applies during a long outage.');
        } catch (PilotStoppedException) {
            $this->assertSame('campaign_duration_budget_exhausted', $campaign->state()['stopped']['reason']);
        }
        Http::assertNothingSent();
    }

    public function test_existing_terminal_stop_needs_reviewed_recovery_before_policy_can_adopt_pending_probe(): void
    {
        DocumentSource::create(['slug' => 'lex', 'name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);
        app(AutonomousCampaign::class)->prepare(self::ID);
        $campaign = app(DurableCampaign::class);
        $campaign->claim(self::ID);
        $campaign->resume();
        $this->outage($campaign); // Default remains terminal until explicitly opted in.
        $campaign->recordFinalReport(app(CampaignReportingService::class)->campaign(self::ID));
        $campaign->release();
        $final = DB::table('scrape_campaign_checkpoints')->where('name', 'final-report')->first();
        try {
            $campaign->enableTransportWait(self::ID);
            $this->fail('Enabling policy cannot reopen a terminal campaign.');
        } catch (PilotStoppedException) {
            $this->assertSame('stopped', $campaign->state()['status']);
        }
        $this->travel(16)->minutes();
        $campaign->recoverNetwork(self::ID);
        $before = ScrapeCampaign::first()->getRawOriginal();
        $campaign->enableTransportWait(self::ID);
        $state = $campaign->state();
        $this->assertSame('paused', $state['status']);
        $this->assertTrue($state['transport_wait']['active']);
        $this->assertFalse($state['network_probe_required']);
        foreach (['configuration_snapshot', 'used_http_requests', 'selected_source_count', 'started_at'] as $key) {
            $this->assertSame($before[$key], ScrapeCampaign::first()->getRawOriginal()[$key]);
        }
        $this->assertEquals($final, DB::table('scrape_campaign_checkpoints')->where('name', 'final-report')->first());
        Http::assertNothingSent();
        $campaign->claim(self::ID);
        $campaign->resume();
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake([self::URL => Http::response('synthetic reachable source', 200)]);
        $campaign->serviceTransportWait();
        $this->assertFalse($campaign->state()['transport_wait']['active']);
        Http::assertSentCount(1);
    }

    public function test_interrupted_import_waits_then_resumes_with_same_source_attempt_history(): void
    {
        $campaign = $this->prepared();
        $run = app(ScrapeRunManager::class)->start(DocumentSource::first(), 'full', 1, ['8506802']);
        $campaign->saveControl(['phase' => 'wait', 'scope_index' => 0, 'pool' => [], 'run_id' => $run->id]);
        $campaign->release();
        $fake = Mockery::mock(DocumentScraper::class);
        $fake->shouldReceive('fetch')->twice()->andReturnUsing(function () {
            app(LexHttpClient::class)->get(self::URL);

            return FixtureInputs::seed() + ['diagnostics' => ['primary_format' => 'html', 'quality' => []]];
        });
        $this->app->instance(DocumentScraper::class, $fake);
        $reachable = false;
        Http::fake(function () use (&$reachable) {
            if (! $reachable) {
                throw new ConnectionException('synthetic interrupted import');
            }

            return Http::response('synthetic reachable source', 200);
        });
        $worker = app(AutonomousCampaignWorker::class);
        $worker->begin();
        try {
            $worker->tick(self::ID); // Explicit discovery receipt.
            $worker->tick(self::ID); // Two HTTP failures in one source attempt open the circuit.
            $this->assertSame('retry_wait', $run->items()->first()->status);
            $this->assertSame(1, $run->items()->first()->attempts);
            $this->assertTrue($campaign->state()['transport_wait']['active']);
            $worker->tick(self::ID);
            $this->assertSame(1, $run->items()->first()->attempts);
            $this->assertDatabaseCount('failed_jobs', 0);
            $this->travel(16)->minutes();
            $reachable = true;
            $campaign->claim(self::ID);
            $campaign->serviceTransportWait();
            $campaign->release();
            $worker->tick(self::ID);
            $this->assertSame('imported', $run->items()->first()->status, json_encode(['item' => $run->items()->first()->toArray(), 'queue' => DB::table('jobs')->get(), 'state' => $campaign->state()['transport_wait']]));
            $this->assertSame(2, $run->items()->first()->attempts);
            $this->assertDatabaseCount('documents', 1);
            $this->assertDatabaseCount('failed_jobs', 0);
        } finally {
            $worker->close();
        }
    }

    public function test_404_probe_only_confirms_transport_and_no_empty_content_is_created(): void
    {
        $campaign = $this->prepared();
        $this->outage($campaign);
        $this->travel(16)->minutes();
        Http::fake([self::URL => Http::response('not found', 404)]);
        $campaign->serviceTransportWait();
        $this->assertFalse($campaign->state()['transport_wait']['active']);
        $this->assertDatabaseCount('document_contents', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $campaign->serviceTransportWait();
        Http::assertSentCount(1);
    }
}
