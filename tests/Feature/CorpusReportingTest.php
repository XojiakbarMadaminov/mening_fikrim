<?php

namespace Tests\Feature;

use App\Models\DocumentContent;
use App\Models\DocumentSource;
use App\Services\Documents\DocumentImporter;
use App\Services\Reporting\CampaignReportingService;
use App\Services\Reporting\CorpusReportingTools;
use App\Services\Scraping\DurableCampaign;
use DateTimeImmutable;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class CorpusReportingTest extends TestCase
{
    use DatabaseMigrations;

    private string $token = 'test-reporting-token-32-characters-long-only';

    protected function setUp(): void
    {
        parent::setUp();
        config(['corpus_reporting.token_sha256' => hash('sha256', $this->token)]);
        $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        app(DocumentImporter::class)->import($source->id, FixtureInputs::seed(), new DateTimeImmutable('2026-10-28T12:00:00+05:00'));
    }

    protected function tearDown(): void
    {
        app(DurableCampaign::class)->release();
        parent::tearDown();
    }

    public function test_api_requires_token_and_gate_and_fails_closed_when_unconfigured(): void
    {
        $this->get('/api/admin/corpus/statistics')->assertStatus(401);
        $this->withToken('incorrect-token-with-long-value-123456789')->getJson('/api/admin/corpus/statistics')->assertStatus(401);
        Auth::forgetGuards();
        config(['corpus_reporting.token_sha256' => '']);
        $this->withToken($this->token)->getJson('/api/admin/corpus/statistics')->assertStatus(401);
        Auth::forgetGuards();
        $this->actingAs(new GenericUser(['id' => 'not-an-admin', 'corpus_read' => false]), 'corpus-reports')->getJson('/api/admin/corpus/statistics')->assertStatus(403);
    }

    public function test_reports_match_database_counts_languages_quality_and_archive_mode(): void
    {
        DocumentContent::where('language', 'en')->update(['quality_status' => 'needs_review', 'quality_issues' => json_encode(['pdf_structure_unverified'])]);
        $this->withToken($this->token)->getJson('/api/admin/corpus/statistics')->assertOk()->assertJsonPath('canonical_documents', 1)->assertJsonPath('contents', 3)
            ->assertJsonPath('content_quality.verified', 2)->assertJsonPath('content_quality.needs_review', 1)->assertJsonPath('content_languages.en', 1)
            ->assertJsonPath('archive.real_blobs', 0)->assertJsonPath('archive.retention_enabled', false);
        $this->withToken($this->token)->getJson('/api/admin/corpus/quality')->assertOk()->assertJsonPath('content_quality.needs_review', 1)->assertJsonCount(3, 'by_language_and_quality');
    }

    public function test_read_only_snapshot_actually_rejects_writes_and_returns_timestamp(): void
    {
        $writes = [];
        DB::listen(function ($event) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|alter|drop|truncate)/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        $report = app(CampaignReportingService::class)->corpus();
        $this->assertNotEmpty($report['observed_at']);
        $this->assertSame([], $writes);
        $this->assertSame(1, $report['today']['new_canonical_documents']);
    }

    public function test_tools_reject_sql_shell_file_access_campaign_controls_and_unknown_arguments(): void
    {
        $tools = app(CorpusReportingTools::class);
        foreach (['sql', 'shell', 'read_file', 'start_campaign', 'stop_campaign', 'pause_campaign', 'delete_campaign'] as $tool) {
            try {
                $tools->call($tool, ['sql' => 'DROP TABLE documents']);
                $this->fail('Write or arbitrary execution tool accepted.');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('read-only', $e->getMessage());
            }
        }
        $this->withToken($this->token)->getJson('/api/admin/corpus/tools/get_corpus_summary?sql=SELECT%201')->assertStatus(422);
        $this->withToken($this->token)->postJson('/api/admin/corpus/campaigns/start')->assertStatus(405);
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_uzbek_reporting_works_without_any_llm_and_unsupported_intent_is_explicit(): void
    {
        $this->withToken($this->token)->getJson('/api/admin/corpus/assistant?'.http_build_query(['question' => 'Bugun nechta hujjat import qilindi?']))
            ->assertOk()->assertJsonPath('language', 'uz')->assertJsonPath('tool', 'get_corpus_summary')->assertJsonPath('evidence.canonical_documents', 1);
        $this->withToken($this->token)->getJson('/api/admin/corpus/assistant?'.http_build_query(['question' => 'DROP TABLE documents']))->assertStatus(422);
    }

    public function test_campaign_report_does_not_confuse_existing_content_new_documents_or_global_failed_jobs(): void
    {
        $campaign = app(DurableCampaign::class);
        $campaign->create('report-test', 10, 10, 1);
        $campaign->reserve('run-1');
        $campaign->observe(200);
        $campaign->saveControl(['phase' => 'discover', 'private_secret' => 'must-not-be-exposed']);
        $report = app(CampaignReportingService::class)->campaign('report-test');
        $this->assertSame('report-test', $report['campaign_id']);
        $this->assertSame(0, $report['new_canonical']);
        $this->assertSame(0, $report['new_canonical_content_representations']);
        $this->assertSame(1, $report['current_total_canonical']);
        $this->assertSame(9, $report['http']['remaining']);
        $this->assertSame(0, $report['technical_failed_items']);
        $this->assertNull($report['timing']['active_processing_seconds']);
        $this->assertStringNotContainsString('must-not-be-exposed', json_encode($report));
        $this->assertStringNotContainsString('owner_token', json_encode($report));
        $this->assertSame(0, Artisan::call('documents:campaign', ['action' => 'report', '--id' => 'report-test', '--format' => 'markdown']));
        $this->assertStringContainsString('# Corpus report', Artisan::output());
    }

    public function test_failed_diagnostics_are_bounded_and_arbitrary_limits_are_rejected(): void
    {
        $this->withToken($this->token)->getJson('/api/admin/corpus/tools/get_failed_import_summary?limit=31')->assertStatus(422);
        $this->withToken($this->token)->getJson('/api/admin/corpus/tools/get_failed_import_summary?limit=1')->assertOk()->assertJsonPath('limit', 1);
        $this->withToken($this->token)->getJson('/api/admin/corpus/campaigns/missing/report')->assertStatus(404);
    }

    public function test_postgresql_read_only_snapshot_rejects_accidental_writer(): void
    {
        $method = new \ReflectionMethod(CampaignReportingService::class, 'snapshot');
        try {
            $method->invoke(app(CampaignReportingService::class), fn () => ['deleted' => DB::table('documents')->delete()]);
            $this->fail('PostgreSQL must reject a write inside the reporting snapshot.');
        } catch (QueryException $e) {
            $this->assertSame('25006', (string) $e->getCode());
        }
        $this->assertDatabaseCount('documents', 1);
    }

    public function test_unexpected_report_errors_do_not_leak_sql_credentials_or_debug_stack(): void
    {
        config(['app.debug' => true]);
        $mock = \Mockery::mock(CampaignReportingService::class);
        $mock->shouldReceive('corpus')->once()->andThrow(new \RuntimeException('password=secret SELECT private_config'));
        $this->app->instance(CampaignReportingService::class, $mock);
        $response = $this->withToken($this->token)->getJson('/api/admin/corpus/statistics')->assertStatus(503);
        $this->assertStringNotContainsString('secret', $response->getContent());
        $this->assertStringNotContainsString('SELECT', $response->getContent());
        $response->assertJsonStructure(['message']);
    }

    public function test_stored_final_snapshot_is_distinct_from_current_metrics_and_is_not_fabricated(): void
    {
        $campaign = app(DurableCampaign::class);
        $campaign->create('report-final', 10, 10, 1);
        $this->withToken($this->token)->getJson('/api/admin/corpus/campaigns/report-final/report?snapshot=final')->assertOk()->assertJsonPath('available', false)->assertJsonPath('report', null);
        $campaign->stop('operator_stop');
        $original = app(CampaignReportingService::class)->campaign('report-final');
        $campaign->recordFinalReport($original);
        $campaign->recordFinalReport([...$original, 'new_canonical' => 999]); // Must retain first terminal snapshot.
        $this->withToken($this->token)->getJson('/api/admin/corpus/campaigns/report-final/report?snapshot=final')->assertOk()->assertJsonPath('available', true)->assertJsonPath('report.new_canonical', 0);
        $this->assertSame(0, Artisan::call('documents:campaign', ['action' => 'report', '--id' => 'report-final', '--snapshot' => 'final', '--format' => 'json']));
        $this->assertSame('stored_final', json_decode(Artisan::output(), true)['snapshot_type']);
        $this->assertTrue(app(CorpusReportingTools::class)->call('get_campaign_statistics', ['campaign_id' => 'report-final', 'snapshot' => 'final'])['available']);
    }
}
