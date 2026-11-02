<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\ScrapeCampaign;
use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexHttpClient;
use App\Services\Reporting\CampaignReportingService;
use App\Services\Scraping\AutonomousCampaign;
use App\Services\Scraping\AutonomousCampaignWorker;
use App\Services\Scraping\CampaignIntegrity;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Fixtures\FixtureInputs;
use Tests\Fixtures\VirtualLexHttpClient;
use Tests\TestCase;

class NetworkCampaignRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    private const ID = 'corpus-30000-20261030';

    private const URL = 'https://lex.uz/uz/actinfo/card1/7238011';

    private ?AutonomousCampaign $controller = null;

    private ?AutonomousCampaignWorker $worker = null;

    protected function tearDown(): void
    {
        $this->controller?->close();
        $this->worker?->close();
        app(DurableCampaign::class)->release();
        foreach (ScrapeCampaign::where('campaign_type', 'corpus_collection')->get() as $row) {
            $row->checkpoints()->delete();
            $row->delete();
        }
        parent::tearDown();
    }

    /** Simulates the committed transport-observation boundary, with a real pending receipt. */
    private function stopped(): array
    {
        $source = DocumentSource::create(['slug' => 'lex', 'name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);
        app(AutonomousCampaign::class)->prepare(self::ID);
        $campaign = app(DurableCampaign::class);
        $campaign->claim(self::ID);
        $campaign->resume();
        $run = app(ScrapeRunManager::class)->start($source, 'full', 1, ['8506802']);
        $campaign->saveControl(['phase' => 'wait', 'scope_index' => 0, 'pool' => [], 'run_id' => $run->id]);
        for ($i = 0; $i < 2; $i++) {
            $campaign->reserve('network-fixture', 'GET', self::URL);
            $campaign->observe(0);
        }
        $campaign->recordFinalReport(app(CampaignReportingService::class)->campaign(self::ID));
        $campaign->release();
        $this->travel(16)->minutes();

        return [ScrapeCampaign::first()->getRawOriginal(), DB::table('jobs')->orderBy('id')->get()->toJson()];
    }

    public function test_recovery_preserves_receipts_counters_and_terminal_evidence_then_probes_shared_budget(): void
    {
        [$before, $jobs] = $this->stopped();
        $final = DB::table('scrape_campaign_checkpoints')->where('name', 'final-report')->first();
        $campaign = app(DurableCampaign::class);
        $state = $campaign->recoverNetwork(self::ID);
        $this->assertSame('paused', $state['status']);
        $this->assertSame($jobs, DB::table('jobs')->orderBy('id')->get()->toJson());
        foreach (['started_at', 'configuration_snapshot', 'used_http_requests', 'selected_source_count', 'max_http_requests', 'max_source_selections', 'target_new_canonical'] as $key) {
            $this->assertSame($before[$key], ScrapeCampaign::first()->getRawOriginal()[$key]);
        }
        $this->assertTrue($state['network_probe_required']);
        Http::assertNothingSent();
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake([self::URL => Http::response('synthetic metadata response', 200)]);
        $state = $campaign->probeNetwork(self::ID);
        $this->assertSame('running', $state['status']);
        $this->assertSame(3, $state['used']); // two previous failures plus the one counted probe
        $this->assertFalse($state['network_probe_required']);
        $this->assertSame(2, $state['http_status_counts'][0]);
        $this->assertSame($jobs, DB::table('jobs')->orderBy('id')->get()->toJson());
        $this->assertEquals($final, DB::table('scrape_campaign_checkpoints')->where('name', 'final-report')->first());
        $this->assertDatabaseHas('scrape_campaign_checkpoints', ['name' => 'operator-recovery-1']);
        $this->assertDatabaseHas('scrape_campaign_checkpoints', ['name' => 'network-probe-1']);
        $this->assertFalse(app(CampaignReportingService::class)->finalReport(self::ID)['available']);
        Http::assertSentCount(1);

        // The same queued receipt continues through the original worker/importer, without duplication.
        $scraper = Mockery::mock(DocumentScraper::class);
        $scraper->shouldReceive('fetch')->once()->andReturn(FixtureInputs::seed() + ['diagnostics' => ['primary_format' => 'html', 'quality' => []]]);
        $this->app->instance(DocumentScraper::class, $scraper);
        $this->worker = app(AutonomousCampaignWorker::class);
        $this->worker->begin();
        $this->worker->tick(self::ID);
        $this->worker->tick(self::ID);
        $this->assertSame(1, Document::count());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_controller_cannot_send_requests_before_successful_probe(): void
    {
        $this->stopped();
        app(DurableCampaign::class)->recoverNetwork(self::ID);
        $this->controller = app(AutonomousCampaign::class);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('probe-network');
        $this->controller->begin(self::ID);
    }

    public function test_cooldown_access_stop_and_exhausted_budget_are_not_bypassed(): void
    {
        $this->stopped();
        $campaign = app(DurableCampaign::class);
        foreach ([['stopped_at' => now()], ['stopped_at' => now()->subHour(), 'stop_reason' => 'source_http_403'], ['stop_reason' => 'source_http_0_repeated', 'used_http_requests' => 360000]] as $update) {
            ScrapeCampaign::first()->update($update);
            try {
                $campaign->recoverNetwork(self::ID);
                $this->fail('Invalid recovery must be rejected.');
            } catch (\RuntimeException) {
                $this->assertSame('stopped', ScrapeCampaign::first()->status);
            }
        }
        $this->assertDatabaseMissing('scrape_campaign_checkpoints', ['name' => 'operator-recovery-1']);
        Http::assertNothingSent();
    }

    public function test_foreign_or_live_receipt_prevents_recovery_without_queue_changes(): void
    {
        [, $jobs] = $this->stopped();
        $job = DB::table('jobs')->first();
        DB::table('jobs')->where('id', $job->id)->update(['reserved_at' => now()->timestamp]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('live or foreign');
        app(DurableCampaign::class)->recoverNetwork(self::ID);
    }

    public function test_corrupt_content_prevents_recovery_and_preserves_stop(): void
    {
        $this->stopped();
        $integrity = Mockery::mock(CampaignIntegrity::class);
        $integrity->shouldReceive('audit')->once()->andReturn(['errors' => ['invalid_content:42'], 'pending_jobs' => 1]);
        $this->app->instance(CampaignIntegrity::class, $integrity);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('integrity audit failed');
        app(DurableCampaign::class)->recoverNetwork(self::ID);
    }

    public function test_probe_access_denial_remains_terminal_and_saves_new_epoch_report(): void
    {
        $this->stopped();
        $campaign = app(DurableCampaign::class);
        $campaign->recoverNetwork(self::ID);
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake([self::URL => Http::response('denied', 403)]);
        try {
            $campaign->probeNetwork(self::ID);
            $this->fail('Access denial must stop.');
        } catch (\RuntimeException) {
            $this->assertSame('stopped', $campaign->state()['status']);
            $this->assertSame('source_http_403', $campaign->state()['stopped']['reason']);
        }
        $this->assertTrue(app(CampaignReportingService::class)->finalReport(self::ID)['available']);
        Http::assertSentCount(1);
    }

    public function test_failed_probe_stops_after_two_transport_attempts_without_refunding_budget(): void
    {
        $this->stopped();
        $campaign = app(DurableCampaign::class);
        $campaign->recoverNetwork(self::ID);
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake(fn () => throw new ConnectionException('synthetic transport failure'));
        try {
            $campaign->probeNetwork(self::ID);
            $this->fail('Repeated transport failure must stop.');
        } catch (\RuntimeException) {
            $this->assertSame('source_http_0_repeated', $campaign->state()['stopped']['reason']);
            $this->assertSame(4, $campaign->state()['used']);
        }
        $this->assertTrue(app(CampaignReportingService::class)->finalReport(self::ID)['available']);
    }

    public function test_probe_404_only_confirms_transport_and_repeat_probe_cannot_stop_running_campaign(): void
    {
        $this->stopped();
        $campaign = app(DurableCampaign::class);
        $campaign->recoverNetwork(self::ID);
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake([self::URL => Http::response('missing resource', 404)]);
        $state = $campaign->probeNetwork(self::ID);
        $this->assertSame('running', $state['status']);
        $this->assertSame(3, $state['used']);
        $this->assertFalse($state['network_probe_required']);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_contents', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        try {
            $campaign->probeNetwork(self::ID);
            $this->fail('Repeated probe must be refused without any mutation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No reviewed network recovery', $e->getMessage());
        }
        $this->assertSame('running', $campaign->state()['status']);
        $this->assertSame(3, $campaign->state()['used']);
        Http::assertSentCount(1);
    }

    public function test_historical_access_restriction_and_recovery_ceiling_are_not_bypassed(): void
    {
        $this->stopped();
        $row = ScrapeCampaign::first();
        $original = $row->state;
        foreach ([['http_status_counts' => ['403' => 1, '0' => 2]], ['network_recovery_count' => 3]] as $update) {
            $row->update(['state' => array_replace($original, $update)]);
            try {
                app(DurableCampaign::class)->recoverNetwork(self::ID);
                $this->fail('Restricted recovery must fail.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('transport-only stop', $e->getMessage());
            }
        }
        $this->assertSame('stopped', $row->fresh()->status);
        $this->assertDatabaseMissing('scrape_campaign_checkpoints', ['name' => 'operator-recovery-1']);
        Http::assertNothingSent();
    }
}
