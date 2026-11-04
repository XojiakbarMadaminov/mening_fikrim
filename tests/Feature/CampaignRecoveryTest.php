<?php

namespace Tests\Feature;

use App\Jobs\ImportDocumentJob;
use App\Models\DocumentSource;
use App\Models\ScrapeCampaign;
use App\Models\ScrapeRun;
use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexRequestException;
use App\Services\Reporting\CampaignReportingService;
use App\Services\Scraping\AutonomousCampaign;
use App\Services\Scraping\CampaignIntegrity;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\ScrapeRunManager;
use App\Services\Scraping\ScrapingDependencyHealth;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class CampaignRecoveryTest extends TestCase
{
    use DatabaseMigrations;

    private const ID = 'corpus-30000-20261030';

    protected function tearDown(): void
    {
        app(DurableCampaign::class)->release();
        foreach (ScrapeCampaign::where('campaign_type', 'corpus_collection')->get() as $row) {
            $row->checkpoints()->delete();
            $row->delete();
        }
        parent::tearDown();
    }

    /** Synthetic regression for the real 404-then-success history; no source HTTP. */
    private function stopped(int $httpStatus = 404): array
    {
        $source = DocumentSource::create(['slug' => 'lex', 'name' => 'Lex', 'type' => 'scraper', 'is_active' => true]);
        app(AutonomousCampaign::class)->prepare(self::ID);
        $campaign = app(DurableCampaign::class);
        $campaign->claim(self::ID);
        $campaign->resume();
        $run = app(ScrapeRunManager::class)->start($source, 'full', 1, ['8506802']);
        $scraper = Mockery::mock(DocumentScraper::class);
        $scraper->shouldReceive('fetch')->once()->andReturn(FixtureInputs::seed() + ['diagnostics' => ['primary_format' => 'html', 'quality' => []]]);
        $this->app->instance(DocumentScraper::class, $scraper);
        for ($i = 0; $i < 2; $i++) {
            app('queue.worker')->runNextJob('database', 'scraping', new WorkerOptions(backoff: 0, memory: 256, timeout: 900, sleep: 0, maxTries: 3, force: true));
        }
        $item = $run->items()->first();
        $this->assertSame(true, $item->outcome['persistence_complete']);
        $job = new ImportDocumentJob($source->id, '8506802', runId: $run->id);
        $failureId = DB::table('failed_jobs')->insertGetId([
            'uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'scraping',
            'payload' => json_encode(['displayName' => $job->displayName(), 'data' => ['commandName' => ImportDocumentJob::class, 'command' => serialize($job)]]),
            'exception' => LexRequestException::class.': Lex.uz request failed (HTTP '.$httpStatus.').', 'failed_at' => now(),
        ]);
        $campaign->stop('stage_integrity_audit_failed');
        $campaign->recordFinalReport(app(CampaignReportingService::class)->campaign(self::ID));
        $campaign->release();

        return [$failureId, ScrapeCampaign::first()->getRawOriginal(), $item];
    }

    public function test_explicit_recovery_preserves_budgets_data_failure_and_original_terminal_snapshot(): void
    {
        [$failureId, $before] = $this->stopped();
        $failure = DB::table('failed_jobs')->first();
        $final = DB::table('scrape_campaign_checkpoints')->where('name', 'final-report')->first();
        $campaign = app(DurableCampaign::class);
        $state = $campaign->recoverNotFound(self::ID, $failureId);
        $this->assertSame('paused', $state['status']);
        $after = ScrapeCampaign::first()->getRawOriginal();
        foreach (['started_at', 'used_http_requests', 'selected_source_count', 'max_http_requests', 'max_source_selections', 'target_new_canonical', 'configuration_snapshot', 'baseline_max_id'] as $key) {
            $this->assertSame($before[$key], $after[$key], $key.' must not reset');
        }
        $this->assertEquals($failure, DB::table('failed_jobs')->first());
        $this->assertEquals($final, DB::table('scrape_campaign_checkpoints')->where('name', 'final-report')->first());
        $journal = ScrapeCampaign::first()->checkpoints()->where('name', 'operator-recovery-1')->firstOrFail();
        $journalBefore = $journal->state['before'];
        ksort($before);
        ksort($journalBefore);
        $this->assertSame($before, $journalBefore);
        $this->assertSame([], $journal->state['audit']['errors']);
        $this->assertFalse(app(CampaignReportingService::class)->finalReport(self::ID)['available']);
        $campaign->claim(self::ID);
        $campaign->resume();
        $campaign->stop('operator_stop');
        $campaign->recordFinalReport(app(CampaignReportingService::class)->campaign(self::ID));
        $this->assertTrue(app(CampaignReportingService::class)->finalReport(self::ID)['available']);
        $this->assertDatabaseHas('scrape_campaign_checkpoints', ['name' => 'final-report-1']);
        $this->assertEquals($final, DB::table('scrape_campaign_checkpoints')->where('name', 'final-report')->first());
        Http::assertNothingSent();
    }

    public function test_recovery_rejects_403_and_preserves_stopped_state(): void
    {
        [$failureId] = $this->stopped(403);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not an eligible');
        app(DurableCampaign::class)->recoverNotFound(self::ID, $failureId);
    }

    public function test_recovery_rolls_back_if_content_integrity_is_broken(): void
    {
        [$failureId] = $this->stopped();
        DB::table('document_contents')->update(['content' => 'tampered']);
        try {
            app(DurableCampaign::class)->recoverNotFound(self::ID, $failureId);
            $this->fail('Corrupt content cannot pass operator recovery.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('integrity audit failed', $e->getMessage());
        }
        $this->assertSame('stopped', ScrapeCampaign::first()->status);
        $this->assertArrayNotHasKey('acknowledged_failed_jobs', ScrapeCampaign::first()->state);
        $this->assertDatabaseMissing('scrape_campaign_checkpoints', ['name' => 'operator-recovery-1']);
    }

    public function test_acknowledgement_never_hides_future_failures_or_changes_to_historical_error(): void
    {
        [$failureId] = $this->stopped();
        app(DurableCampaign::class)->recoverNotFound(self::ID, $failureId);
        $row = ScrapeCampaign::first();
        $baseline = $row->checkpoints()->where('name', 'preservation-baseline')->first()->state['baseline'];
        $this->assertSame([], app(CampaignIntegrity::class)->audit($baseline, self::ID)['errors']);
        DB::table('failed_jobs')->where('id', $failureId)->update(['exception' => 'changed']);
        $this->assertContains('acknowledged_failed_job_changed:'.$failureId, app(CampaignIntegrity::class)->audit($baseline, self::ID)['errors']);
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'scraping', 'payload' => '{}', 'exception' => 'new unexpected failure', 'failed_at' => now()]);
        $this->assertContains('failed_jobs_changed', app(CampaignIntegrity::class)->audit($baseline, self::ID)['errors']);
    }

    public function test_recovery_rejects_exhausted_budget(): void
    {
        [$failureId] = $this->stopped();
        ScrapeCampaign::first()->update(['used_http_requests' => 360000]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('remaining original budgets');
        app(DurableCampaign::class)->recoverNotFound(self::ID, $failureId);
    }

    /** Synthetic history of the observed missing-binary storm, never main DB or HTTP. */
    private function ocrHistory(): array
    {
        [$oldFailure] = $this->stopped();
        app(DurableCampaign::class)->recoverNotFound(self::ID, $oldFailure);
        $campaign = app(DurableCampaign::class);
        $campaign->claim(self::ID);
        $campaign->resume();
        $run = ScrapeRun::create(['source_id' => DocumentSource::first()->id, 'mode' => 'full', 'started_at' => now(),
            'status' => 'completed_with_errors', 'finished_at' => now(), 'checkpoint' => ['version' => 3, 'campaign_id' => self::ID, 'discovery_finished' => true, 'jobs_reconciled' => true, 'limit' => 2]]);
        $failed = $run->items()->create(['source_external_id' => '8506802', 'status' => 'failed', 'attempts' => 24, 'finished_at' => now(), 'last_error' => 'tesseract not found']);
        $review = $run->items()->create(['source_external_id' => '9990002', 'status' => 'review', 'attempts' => 13, 'finished_at' => now(), 'review_reasons' => ['status' => 'unknown']]);
        $ids = [];
        foreach ([$failed, $review] as $item) {
            $job = new ImportDocumentJob($run->source_id, $item->source_external_id, runId: $run->id);
            $ids[] = DB::table('failed_jobs')->insertGetId(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'scraping',
                'payload' => json_encode(['displayName' => $job->displayName(), 'data' => ['commandName' => ImportDocumentJob::class, 'command' => serialize($job)]]),
                'exception' => ProcessFailedException::class.': tesseract Exit Code: 127; tesseract: not found', 'failed_at' => now()]);
        }
        $campaign->stop('stage_integrity_audit_failed');
        $campaign->recordFinalReport(app(CampaignReportingService::class)->campaign(self::ID));
        $campaign->release();
        $health = Mockery::mock(ScrapingDependencyHealth::class);
        $health->shouldReceive('assertReady')->andReturn(['ok' => true, 'synthetic_regression' => true]);
        $this->app->instance(ScrapingDependencyHealth::class, $health);

        return [$ids, $failed, $review, $run];
    }

    public function test_ocr_recovery_is_audited_bounded_and_does_not_reimport_completed_sources(): void
    {
        [$ids, $failed, $review, $run] = $this->ocrHistory();
        $before = ScrapeCampaign::first()->getRawOriginal();
        $checkpoints = DB::table('scrape_campaign_checkpoints')->orderBy('id')->get()->keyBy('id');
        $failures = DB::table('failed_jobs')->orderBy('id')->get();
        $reviewBefore = $review->refresh()->getRawOriginal();
        $contentBefore = DB::table('document_contents')->orderBy('id')->get();
        app(DurableCampaign::class)->recoverOcr(self::ID, $ids);
        $this->assertSame('paused', ScrapeCampaign::first()->status);
        $after = ScrapeCampaign::first()->getRawOriginal();
        foreach (['started_at', 'used_http_requests', 'selected_source_count', 'target_new_canonical', 'configuration_snapshot', 'baseline_max_id'] as $key) {
            $this->assertSame($before[$key], $after[$key], $key);
        }
        foreach ($checkpoints as $id => $checkpoint) {
            $this->assertEquals($checkpoint, DB::table('scrape_campaign_checkpoints')->find($id));
        }
        $this->assertEquals($failures, DB::table('failed_jobs')->orderBy('id')->get());
        $this->assertSame($reviewBefore, $review->fresh()->getRawOriginal());
        $this->assertSame(24, $failed->fresh()->attempts);
        $this->assertSame(24, $failed->fresh()->outcome['retry_control']['base_attempts']);
        $this->assertSame(3, $failed->fresh()->outcome['retry_control']['limit']);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertFalse($run->fresh()->checkpoint['jobs_reconciled']);
        $this->assertDatabaseHas('scrape_campaign_checkpoints', ['name' => 'operator-recovery-2']);
        $campaign = app(DurableCampaign::class);
        $campaign->claim(self::ID);
        $campaign->resume();
        $scraper = Mockery::mock(DocumentScraper::class);
        $scraper->shouldReceive('fetch')->once()->andReturn(FixtureInputs::seed() + ['diagnostics' => ['primary_format' => 'html', 'quality' => []]]);
        $this->app->instance(DocumentScraper::class, $scraper);
        app('queue.worker')->runNextJob('database', 'scraping', new WorkerOptions(backoff: 0, timeout: 900, sleep: 0, maxTries: 3, force: true));
        $this->assertSame('unchanged', $failed->fresh()->status);
        $this->assertSame(25, $failed->fresh()->attempts);
        app(ScrapeRunManager::class)->resume($run->id);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('documents', 1);
        $contentAfter = DB::table('document_contents')->orderBy('id')->get();
        foreach ($contentBefore as $index => $old) {
            $current = $contentAfter[$index];
            // A real re-fetch legitimately refreshes check/fetch timestamps across a wall-clock second.
            foreach (['last_fetched_at', 'quality_checked_at', 'updated_at'] as $timestamp) {
                $this->assertGreaterThanOrEqual(strtotime($old->$timestamp), strtotime($current->$timestamp));
                unset($old->$timestamp, $current->$timestamp);
            }
        }
        $this->assertEquals($contentBefore, $contentAfter);
        $this->assertSame($reviewBefore, $review->fresh()->getRawOriginal());
        $baseline = ScrapeCampaign::first()->checkpoints()->where('name', 'preservation-baseline')->first()->state['baseline'];
        $this->assertSame([], app(CampaignIntegrity::class)->audit($baseline, self::ID)['errors']);
        DB::table('failed_jobs')->where('id', $ids[0])->update(['exception' => 'changed']);
        $this->assertContains('acknowledged_failed_job_changed:'.$ids[0], app(CampaignIntegrity::class)->audit($baseline, self::ID)['errors']);
        Http::assertNothingSent();
    }

    public function test_ocr_recovery_rolls_back_if_unrelated_failure_is_omitted(): void
    {
        [$ids, $failed] = $this->ocrHistory();
        try {
            app(DurableCampaign::class)->recoverOcr(self::ID, [$ids[0]]);
            $this->fail('Unacknowledged failure must block recovery.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed_jobs_changed', $e->getMessage());
        }
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame('stopped', ScrapeCampaign::first()->status);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseMissing('scrape_campaign_checkpoints', ['name' => 'operator-recovery-2']);
    }
}
