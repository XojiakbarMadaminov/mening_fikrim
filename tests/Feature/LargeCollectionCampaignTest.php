<?php

namespace Tests\Feature;

use App\Models\DocumentSource;
use App\Models\ScrapeCampaign;
use App\Services\Scraping\AutonomousCampaign;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\PilotStoppedException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LargeCollectionCampaignTest extends TestCase
{
    use DatabaseMigrations;

    private const ID = 'corpus-10000-20261029';

    protected function tearDown(): void
    {
        app(DurableCampaign::class)->release();
        // Test-only cleanup allows migration rollback without deleting real campaign evidence.
        $row = ScrapeCampaign::where('campaign_id', self::ID)->first();
        $row?->checkpoints()->delete();
        $row?->delete();
        parent::tearDown();
    }

    private function prepare(): array
    {
        DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);

        return app(AutonomousCampaign::class)->prepare(self::ID);
    }

    public function test_exact_profile_prepares_once_without_http_and_preserves_conservative_settings(): void
    {
        $state = $this->prepare();
        $stored = ScrapeCampaign::first()->getRawOriginal();
        app(AutonomousCampaign::class)->prepare(self::ID);
        $this->assertSame($stored, ScrapeCampaign::first()->getRawOriginal());
        $this->assertSame('paused', $state['status']);
        $this->assertSame(10000, $state['target']);
        $this->assertSame(120000, $state['request_limit']);
        $this->assertSame(25000, $state['source_limit']);
        $this->assertSame(604800, $state['configuration_snapshot']['max_campaign_seconds']);
        $this->assertSame(1, $state['configuration_snapshot']['workers']);
        $this->assertSame(2000, $state['configuration_snapshot']['request_interval_ms']);
        $this->assertSame(400, $state['configuration_snapshot']['per_context_request_limit']);
        $this->assertSame([30, 100, 300, 1000, 3000, 6000, 10000], $state['stages']);
        $this->assertCount(144, $state['configuration_snapshot']['scope']);
        $this->assertDatabaseCount('scrape_campaigns', 1);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_generic_cli_creation_cannot_claim_extended_caps(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(DurableCampaign::class)->create(self::ID, 120000, 25000, 10000);
    }

    public function test_prepared_limits_cannot_be_rewritten(): void
    {
        $this->prepare();
        config(['corpus_campaigns.'.self::ID.'.limits.max_campaign_seconds' => 604799]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('never overwrite');
        app(AutonomousCampaign::class)->prepare(self::ID);
    }

    public function test_database_enforces_extended_upper_bound_and_preserves_record_on_violation(): void
    {
        $this->prepare();
        try {
            DB::transaction(fn () => ScrapeCampaign::where('campaign_id', self::ID)->update(['max_http_requests' => 120001]));
            $this->fail('Database must enforce finite request cap.');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }
        $this->assertSame(120000, ScrapeCampaign::first()->max_http_requests);
    }

    public function test_extended_campaign_stops_at_budget_and_restart_does_not_reset_tokens(): void
    {
        $this->prepare();
        $campaign = app(DurableCampaign::class);
        $campaign->claim(self::ID);
        $campaign->resume();
        ScrapeCampaign::where('campaign_id', self::ID)->update(['used_http_requests' => 119999]);
        $campaign->reserve('test-last-token');
        $campaign->release();
        $campaign->claim(self::ID);
        try {
            $campaign->reserve('test-next-token');
            $this->fail('Budget cannot be replenished by restart.');
        } catch (PilotStoppedException) {
            $this->assertSame(120000, $campaign->state()['used']);
            $this->assertSame('stopped', $campaign->state()['status']);
        }
        Http::assertNothingSent();
    }

    public function test_existing_safety_stop_cannot_be_bypassed_by_large_profile(): void
    {
        DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $campaign = app(DurableCampaign::class);
        $campaign->create('p6-1-completion-150-20261029', 20, 20, 1);
        $campaign->stop('source_http_429');
        $campaign->release();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('do not bypass');
        app(AutonomousCampaign::class)->prepare(self::ID);
    }
}
