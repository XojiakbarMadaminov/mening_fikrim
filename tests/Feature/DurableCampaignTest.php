<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\ScrapeCampaign;
use App\Models\ScrapeRun;
use App\Scraping\LexUz\LexUzScraper;
use App\Services\Scraping\BoundedDiscovery;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\PilotStoppedException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class DurableCampaignTest extends TestCase
{
    use DatabaseMigrations;

    private ?DurableCampaign $campaign = null;

    protected function tearDown(): void
    {
        $this->campaign?->release();
        parent::tearDown();
    }

    private function createCampaign(int $requests = 20, int $sources = 20, array $configuration = []): DurableCampaign
    {
        DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $this->campaign = new DurableCampaign;
        $this->campaign->create('p6-test', $requests, $sources, 1000, $configuration);

        return $this->campaign;
    }

    public function test_discovery_budget_counts_unique_opaque_ids_across_map_and_array_checkpoints(): void
    {
        $campaign = $this->createCampaign(configuration: ['max_discovered_ids' => 2]);
        $campaign->saveCheckpoint('first', ['v1'], ['nodes' => ['alpha' => ['rows' => [['id' => '0']]]]]);
        $campaign->saveCheckpoint('empty', ['v1'], ['nodes' => []]);
        $campaign->saveCheckpoint('second', ['v1'], ['nodes' => ['beta' => ['rows' => ['x' => ['id' => '-2'], 'y' => ['id' => '0']]]]]);
        $this->assertSame(2, $campaign->state()['discovered_source_count']);
        $this->assertSame(2, $campaign->state()['discovery_partition_count']);
        $this->assertSame('running', $campaign->state()['status']);
    }

    public function test_request_budget_and_contexts_survive_cache_clear_and_controller_restart(): void
    {
        $campaign = $this->createCampaign(2);
        $campaign->reserve('discovery');
        $campaign->release();
        Cache::flush(); // Only the isolated test database is used.
        $this->campaign = $campaign = new DurableCampaign;
        $campaign->claim('p6-test');
        $campaign->reserve('run-1');
        $this->assertSame(2, $campaign->state()['used']);
        $contexts = $campaign->state()['contexts'];
        ksort($contexts);
        $this->assertSame(['discovery' => 1, 'run-1' => 1], $contexts);
        try {
            $campaign->reserve('pdf');
            $this->fail('A restart cannot replenish the campaign budget.');
        } catch (PilotStoppedException $e) {
            $this->assertStringContainsString('campaign_request_budget_exhausted', $e->getMessage());
        }
        $this->assertSame('stopped', ScrapeCampaign::first()->status);
        $this->assertSame(2, ScrapeCampaign::first()->used_http_requests);
    }

    public function test_context_cap_is_permanent_and_a_crash_after_reservation_burns_the_token(): void
    {
        $campaign = $this->createCampaign(configuration: ['per_context_request_limit' => 1]);
        $campaign->reserve('run-1'); // Simulated crash before sending any response.
        $campaign->release();
        $this->campaign = $campaign = new DurableCampaign;
        $campaign->claim('p6-test');
        $this->assertGreaterThan(0, $campaign->secondsUntilNextRequest());
        $this->assertGreaterThan(90, $campaign->secondsUntilNextRequest());
        try {
            $campaign->reserve('run-1');
            $this->fail('The per-run cap must survive a restart.');
        } catch (PilotStoppedException $e) {
            $this->assertSame(1, $campaign->state()['used']);
            $this->assertSame('per_run_request_budget_exhausted', $campaign->state()['stopped']['reason']);
        }
    }

    public function test_durable_checkpoints_retain_prefixes_and_children_after_cache_loss(): void
    {
        $campaign = $this->createCampaign();
        $bounds = ['signature', 8, 10, 63];
        $state = ['bounds' => $bounds, 'nodes' => ['one' => ['query' => ['from' => '01.01.2020', 'to' => '02.01.2020'],
            'depth' => 0, 'pages' => [1 => ['total' => 2, 'prefix' => 'committed-prefix']],
            'rows' => ['100' => ['id' => '100']], 'done' => false]]];
        $campaign->saveCheckpoint('oliy-2020', $bounds, $state);
        $campaign->release();
        Cache::flush();
        $this->campaign = $campaign = new DurableCampaign;
        $campaign->claim('p6-test');
        $this->assertEquals($state, $campaign->loadCheckpoint('oliy-2020', $bounds));
        $this->assertSame(1, $campaign->state()['discovery_partition_count']);
        $this->assertSame(1, $campaign->state()['discovered_source_count']);
        $this->expectException(\RuntimeException::class);
        $campaign->loadCheckpoint('oliy-2020', ['different', 8, 10, 63]);
    }

    public function test_campaign_wide_discovery_cap_stops_without_erasing_prior_checkpoint(): void
    {
        $campaign = $this->createCampaign(configuration: ['max_partitions' => 1]);
        $state = ['nodes' => ['one' => ['rows' => [['id' => '100']]]]];
        $campaign->saveCheckpoint('first', ['first'], $state);
        try {
            $campaign->saveCheckpoint('second', ['second'], ['nodes' => ['two' => ['rows' => [['id' => '200']]]]]);
            $this->fail('Partition cap applies across all checkpoint names.');
        } catch (PilotStoppedException $e) {
            $this->assertSame('campaign_discovery_budget_exhausted', $campaign->state()['stopped']['reason']);
            $this->assertDatabaseCount('scrape_campaign_checkpoints', 1);
        }
    }

    public function test_other_postgresql_controller_is_excluded_and_stale_fence_cannot_write(): void
    {
        $campaign = $this->createCampaign();
        $connection = DB::getDefaultConnection();
        config(['database.connections.campaign_competitor' => config('database.connections.'.$connection)]);
        try {
            DB::setDefaultConnection('campaign_competitor');
            try {
                (new DurableCampaign)->claim('p6-test');
                $this->fail('A second PostgreSQL session must not claim the controller lock.');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('global PostgreSQL session lock', $e->getMessage());
            }
        } finally {
            DB::setDefaultConnection($connection);
            DB::purge('campaign_competitor');
        }
        $campaign->assertOwner();
        // An isolated synthetic generation change proves stale in-memory writers are fenced.
        ScrapeCampaign::first()->increment('owner_generation');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Stale campaign controller fence');
        $campaign->selectSources(['100']);
    }

    public function test_source_ids_are_unique_across_restart_and_unresolved_identity_is_excluded(): void
    {
        $campaign = $this->createCampaign(sources: 2);
        $campaign->selectSources(['100', '-100', '100']);
        $campaign->release();
        $this->campaign = $campaign = new DurableCampaign;
        $campaign->claim('p6-test');
        $campaign->selectSources(['100']);
        $this->assertSame(2, $campaign->state()['selected_source_count']);
        try {
            $campaign->selectSources(['1587862']);
            $this->fail('Known conflicting identities remain excluded.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('running', $campaign->state()['status']);
        }
        try {
            $campaign->selectSources(['200']);
            $this->fail('Distinct selections must respect their finite cap.');
        } catch (PilotStoppedException $e) {
            $this->assertSame('bounded_source_selection_budget_exhausted', $campaign->state()['stopped']['reason']);
        }
    }

    public function test_pause_resume_retains_budget_but_terminal_stop_cannot_resume(): void
    {
        $campaign = $this->createCampaign();
        $campaign->reserve('run-1');
        $campaign->pause();
        $campaign->release();
        Cache::flush();
        $this->campaign = $campaign = new DurableCampaign;
        $campaign->resume('p6-test');
        $this->assertSame('running', $campaign->state()['status']);
        $this->assertSame(1, $campaign->state()['used']);
        $campaign->observe(429);
        $campaign->release();
        $this->expectException(PilotStoppedException::class);
        $campaign->resume('p6-test');
    }

    public function test_new_registry_does_not_change_legacy_state_and_old_or_unrelated_documents_do_not_count(): void
    {
        $legacy = ['id' => 'p51', 'used' => 414, 'stopped' => ['reason' => 'approved_target_reached', 'at' => 'historical']];
        Cache::forever('lex:pilot:active', 'p51');
        Cache::forever('lex:pilot:p51', $legacy);
        $campaign = $this->createCampaign();
        $this->assertSame('p51', Cache::get('lex:pilot:active'));
        $this->assertSame($legacy, Cache::get('lex:pilot:p51'));
        $source = DocumentSource::where('slug', 'lex')->firstOrFail();
        $document = Document::create(['source_id' => $source->id, 'external_id' => '100', 'title' => 'Synthetic canonical counter fixture', 'status' => 'active']);
        $this->assertSame(0, $campaign->newCount());
        $run = ScrapeRun::create(['source_id' => $source->id, 'mode' => 'full', 'started_at' => now(), 'checkpoint' => ['campaign_id' => 'p6-test']]);
        foreach (['100', '-100'] as $id) {
            $run->items()->create(['source_external_id' => $id, 'document_id' => $document->id, 'action' => 'created']);
        }
        $this->assertSame(1, $campaign->newCount());
        $this->assertSame(1000, $campaign->state()['target']);
        $this->assertSame([30, 100, 300, 600, 1000], $campaign->state()['stages']);
    }

    public function test_killed_controller_recovers_lock_generation_and_reserved_budget(): void
    {
        $campaign = $this->createCampaign();
        $campaign->release();
        $process = new Process([PHP_BINARY, base_path('tests/Support/campaign_crash.php'), 'p6-test']);
        $process->start();
        try {
            $deadline = microtime(true) + 10;
            while (! str_contains($process->getOutput(), 'RESERVED')) {
                if (! $process->isRunning() || microtime(true) > $deadline) {
                    $this->fail('Crash child did not reserve: '.$process->getErrorOutput());
                }
                usleep(10000);
            }
            $process->signal(9);
            $process->wait();
            Cache::flush();
            $this->campaign = $recovered = new DurableCampaign;
            $deadline = microtime(true) + 3;
            while (true) {
                try {
                    $recovered->claim('p6-test');
                    break;
                } catch (\RuntimeException $e) {
                    if (microtime(true) > $deadline) {
                        throw $e;
                    } usleep(20000);
                }
            }
            $this->assertSame(1, $recovered->state()['used']);
            $this->assertSame(1, $recovered->state()['contexts']['crash-before-send']);
            $this->assertGreaterThanOrEqual(3, $recovered->state()['owner_generation']);
            $recovered->assertOwner();
        } finally {
            if ($process->isRunning()) {
                $process->stop(0, 9);
            }
        }
    }

    public function test_validation_campaign_cannot_import_new_canonicals_and_can_complete_at_zero(): void
    {
        DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $this->campaign = $campaign = new DurableCampaign;
        $campaign->create('preflight', 2000, 200, 0, ['campaign_type' => 'validation']);
        $campaign->auditedStage([]);
        $campaign->complete();
        $this->assertSame('completed', $campaign->state()['status']);
        $this->assertSame(0, $campaign->newCount());
    }

    public function test_completed_discovery_survives_cache_loss_and_does_not_fetch_again(): void
    {
        $campaign = $this->createCampaign();
        $this->app->instance(DurableCampaign::class, $campaign);
        $scraper = \Mockery::mock(LexUzScraper::class);
        $scraper->shouldReceive('discover')->once()->andReturnUsing(function ($query, $callback) {
            $callback(['page' => 1, 'total' => 1, 'rows' => [['id' => '12345', 'url' => 'https://lex.uz/uz/docs/12345']],
                'fingerprint' => 'source-proof', 'prefix_fingerprint' => 'prefix-proof']);

            return ['complete' => true, 'total' => 1, 'unique_ids' => 1, 'pages' => 1];
        });
        $this->app->instance(LexUzScraper::class, $scraper);
        $query = ['section' => 'oliy', 'status' => 'Y', 'from' => '01.01.2020', 'to' => '02.01.2020'];
        $first = app(BoundedDiscovery::class)->collect('complete', $query);
        $campaign->release();
        Cache::flush();
        $this->campaign = $campaign = new DurableCampaign;
        $this->app->instance(DurableCampaign::class, $campaign);
        $campaign->claim('p6-test');
        $this->app->forgetInstance(BoundedDiscovery::class);
        $second = app(BoundedDiscovery::class)->collect('complete', $query);
        $this->assertTrue($second['complete']);
        $this->assertEquals($first['rows'], $second['rows']);
        $this->assertSame(0, $campaign->state()['used']);
    }

    public function test_operator_pause_signal_holds_requests_without_terminalizing_or_resetting_budget(): void
    {
        $campaign = $this->createCampaign();
        $campaign->reserve('first');
        (new DurableCampaign)->requestPause('p6-test');
        try {
            $campaign->assertOpen();
            $this->fail('Pausing must not admit another request.');
        } catch (PilotStoppedException $e) {
            $this->assertSame(10, $e->retryAfter);
        }
        $campaign->pause();
        $campaign->release();
        $this->campaign = $campaign = new DurableCampaign;
        $campaign->resume('p6-test');
        $this->assertSame('running', $campaign->state()['status']);
        $this->assertSame(1, $campaign->state()['used']);
    }

    public function test_discovery_cannot_exceed_the_declared_campaign_depth(): void
    {
        $campaign = $this->createCampaign(configuration: ['max_partition_depth' => 1]);
        $this->app->instance(DurableCampaign::class, $campaign);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fixed campaign page/depth caps');
        app(BoundedDiscovery::class)->collect('bounded', ['section' => 'oliy', 'from' => '01.01.2020', 'to' => '31.12.2020'], maxDepth: 2);
    }

    public function test_recovery_waits_for_reserved_receipt_instead_of_polling_it_as_ready(): void
    {
        $campaign = $this->createCampaign();
        $id = DB::table('jobs')->insertGetId(['queue' => 'scraping', 'payload' => '{}', 'attempts' => 1,
            'reserved_at' => now()->timestamp, 'available_at' => now()->subMinute()->timestamp, 'created_at' => now()->subMinute()->timestamp]);
        $this->assertGreaterThanOrEqual(config('queue.connections.database.retry_after') - 1, $campaign->queueDelaySeconds());
        DB::table('jobs')->where('id', $id)->update(['reserved_at' => null, 'available_at' => now()->addSeconds(10)->timestamp]);
        $this->assertGreaterThanOrEqual(9, $campaign->queueDelaySeconds());
        DB::table('jobs')->where('id', $id)->update(['available_at' => now()->timestamp]);
        $this->assertSame(0, $campaign->queueDelaySeconds());
        $this->assertSame(0, $campaign->state()['used']);
    }
}
