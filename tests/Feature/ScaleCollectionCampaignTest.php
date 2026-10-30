<?php

namespace Tests\Feature;

use App\Models\DocumentSource;
use App\Models\ScrapeCampaign;
use App\Services\Reporting\CampaignReportingService;
use App\Services\Scraping\AutonomousCampaign;
use App\Services\Scraping\BoundedDiscovery;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\PilotStoppedException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ScaleCollectionCampaignTest extends TestCase
{
    use DatabaseMigrations;

    private const ID = 'corpus-30000-20261030';

    private ?AutonomousCampaign $controller = null;

    protected function tearDown(): void
    {
        $this->controller?->close();
        app(DurableCampaign::class)->release();
        // Only the isolated test DB: allow migration rollback while production refuses evidence loss.
        foreach (ScrapeCampaign::where('campaign_type', 'corpus_collection')->get() as $row) {
            $row->checkpoints()->delete();
            $row->delete();
        }
        parent::tearDown();
    }

    private function prepare(): array
    {
        DocumentSource::firstOrCreate(['slug' => 'lex'], ['name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);
        $this->controller = app(AutonomousCampaign::class);

        return $this->controller->prepare(self::ID);
    }

    public function test_profile_is_finite_idempotent_and_has_no_http_until_started(): void
    {
        $state = $this->prepare();
        $stored = ScrapeCampaign::first()->getRawOriginal();
        $this->controller->prepare(self::ID);
        $this->assertSame($stored, ScrapeCampaign::first()->getRawOriginal());
        $this->assertSame(30000, $state['target']);
        $this->assertSame(360000, $state['request_limit']);
        $this->assertSame(75000, $state['source_limit']);
        $this->assertSame(1814400, $state['configuration_snapshot']['max_campaign_seconds']);
        $this->assertSame(64, $state['configuration_snapshot']['single_day_page_cap']);
        $this->assertSame(1, $state['configuration_snapshot']['workers']);
        $this->assertSame(2000, $state['configuration_snapshot']['request_interval_ms']);
        $this->assertCount(397, $state['configuration_snapshot']['scope']);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_incomplete_partition_is_reported_and_next_partition_is_discovered_without_looping(): void
    {
        $discovery = Mockery::mock(BoundedDiscovery::class);
        $discovery->shouldReceive('collect')->once()->ordered()->andReturn([
            'complete' => false, 'root' => ['reason' => 'single_day_overflow', 'total' => 2000], 'rows' => [],
        ]);
        $discovery->shouldReceive('collect')->once()->ordered()->andReturn([
            'complete' => true, 'rows' => [['id' => '1001', 'listing_status' => 'Y']],
        ]);
        $this->app->instance(BoundedDiscovery::class, $discovery);
        $this->prepare();
        $this->controller->begin(self::ID);
        $this->assertTrue($this->controller->tick());
        $this->assertTrue($this->controller->tick());
        $state = app(DurableCampaign::class)->state();
        $this->assertSame('running', $state['status']);
        $this->assertSame(2, $state['control']['scope_index']);
        $this->assertCount(1, $state['control']['coverage_gaps']);
        $this->assertSame('single_day_overflow', $state['control']['coverage_gaps'][0]['reason']);
        $this->assertSame(['1001'], array_map('strval', array_keys($state['control']['pool'])));
        $this->assertTrue($this->controller->tick());
        $this->assertDatabaseCount('jobs', 1);
        $report = app(CampaignReportingService::class)->campaign(self::ID);
        $this->assertCount(1, $report['discovery']['coverage_gaps']);
        $this->assertFalse($report['discovery']['global_coverage_verified']);
    }

    public function test_explicit_successor_preserves_stopped_predecessor(): void
    {
        DocumentSource::create(['slug' => 'lex', 'name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);
        app(AutonomousCampaign::class)->prepare('corpus-10000-20261029');
        $campaign = app(DurableCampaign::class);
        $campaign->claim('corpus-10000-20261029');
        $campaign->stop('unreconciled_discovery_partition');
        $campaign->release();
        $old = ScrapeCampaign::first()->getRawOriginal();
        $this->prepare();
        $this->assertSame($old, ScrapeCampaign::first()->getRawOriginal());
        $this->assertDatabaseCount('scrape_campaigns', 2);
    }

    public function test_access_denial_predecessor_cannot_be_bypassed(): void
    {
        DocumentSource::create(['slug' => 'lex', 'name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);
        $campaign = app(DurableCampaign::class);
        $campaign->create('corpus-10000-20261029', 20, 20, 1);
        $campaign->stop('source_http_403');
        $campaign->release();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('do not bypass');
        $this->prepare();
    }

    public function test_generic_start_cannot_claim_30000_caps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(DurableCampaign::class)->create(self::ID, 360000, 75000, 30000);
    }

    public function test_sql_budget_cap_is_enforced(): void
    {
        $this->prepare();
        try {
            DB::transaction(fn () => ScrapeCampaign::first()->update(['max_http_requests' => 360001]));
            $this->fail('Finite SQL cap required.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(360000, ScrapeCampaign::first()->max_http_requests);
    }

    public function test_budget_stays_spent_across_restart_and_context_ceiling_is_campaign_specific(): void
    {
        $this->prepare();
        $campaign = app(DurableCampaign::class);
        $campaign->claim(self::ID);
        $campaign->resume();
        $row = ScrapeCampaign::first();
        $state = $row->state;
        $state['contexts'] = array_fill_keys(array_map(fn ($i) => 'old-'.$i, range(1, 1024)), 1);
        $row->update(['used_http_requests' => 359999, 'state' => $state]);
        $campaign->reserve('new-context');
        $campaign->release();
        $campaign->claim(self::ID);
        try {
            $campaign->reserve('beyond-budget');
            $this->fail('No request after budget exhaustion.');
        } catch (PilotStoppedException) {
            $this->assertSame(360000, $campaign->state()['used']);
            $this->assertSame('stopped', $campaign->state()['status']);
        }
        Http::assertNothingSent();
    }
}
