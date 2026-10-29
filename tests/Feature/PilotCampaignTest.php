<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSource;
use App\Services\Documents\DocumentImporter;
use App\Services\Scraping\PilotCampaign;
use App\Services\Scraping\PilotStoppedException;
use App\Services\Scraping\ScrapeRunManager;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\FixtureInputs;
use Tests\Fixtures\VirtualLexHttpClient;
use Tests\TestCase;

class PilotCampaignTest extends TestCase
{
    use DatabaseMigrations;

    public function test_budget_is_shared_across_clients_contexts_and_database_cache_reload(): void
    {
        $pilot = app(PilotCampaign::class);
        $pilot->create('test', 2, 200);
        Http::fake(['*' => Http::response('ok', 200)]);
        foreach (['discovery', 'run-1'] as $context) {
            $client = new VirtualLexHttpClient;
            $client->context($context);
            $client->get('https://lex.uz/uz/search/nat');
        }
        $this->assertSame(2, (new PilotCampaign)->state()['used']);
        $this->assertSame(0, DB::table('cache')->where('key', 'like', '%lex:pilot:test')->where('expiration', '<', time() + 86400)->count());
        try {
            (new VirtualLexHttpClient)->get('https://lex.uz/pdffile/1', true);
            $this->fail('Cross-run budget must stop before a third request.');
        } catch (PilotStoppedException $e) {
            $this->assertStringContainsString('budget_exhausted', $e->getMessage());
        }
        Http::assertSentCount(2);
        $this->assertNotNull($pilot->state()['stopped']);
    }

    public function test_429_latches_stop_without_inline_retry_and_survives_new_client(): void
    {
        app(PilotCampaign::class)->create('test', 10, 200);
        Http::fake(['*' => Http::response('limited', 429, ['Retry-After' => '2'])]);
        for ($i = 0; $i < 2; $i++) {
            try {
                (new VirtualLexHttpClient)->get('https://lex.uz/uz/docs/1');
                $this->fail('A stopped pilot cannot resume.');
            } catch (PilotStoppedException $e) {
                $this->assertStringContainsString('source_http_429', $e->getMessage());
            }
        }
        Http::assertSentCount(1);
    }

    public function test_two_consecutive_5xx_stop_before_third_retry(): void
    {
        app(PilotCampaign::class)->create('test', 10, 200);
        Http::fake(['*' => Http::response('error', 503)]);
        try {
            (new VirtualLexHttpClient)->get('https://lex.uz/uz/docs/1');
            $this->fail('Repeated source errors must stop the campaign.');
        } catch (PilotStoppedException $e) {
            $this->assertStringContainsString('source_http_503_repeated', $e->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_repeated_connection_timeouts_stop_the_campaign(): void
    {
        app(PilotCampaign::class)->create('test', 10, 200);
        Http::fake(fn () => throw new ConnectionException('offline timeout'));
        $this->expectException(PilotStoppedException::class);
        try {
            (new VirtualLexHttpClient)->get('https://lex.uz/uz/docs/1');
        } finally {
            $this->assertSame(2, app(PilotCampaign::class)->state()['used']);
        }
    }

    public function test_campaign_cannot_be_recreated_to_reset_its_budget(): void
    {
        $pilot = app(PilotCampaign::class);
        $pilot->create('test', 10, 200);
        $pilot->stop('operator_stop');
        $this->expectException(\RuntimeException::class);
        $pilot->create('replacement', 4000, 200);
    }

    public function test_canonical_count_excludes_existing_documents_and_alias_imports(): void
    {
        $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $input = FixtureInputs::seed();
        $importer = app(DocumentImporter::class);
        $importer->import($source->id, $input, new DateTimeImmutable('2026-10-28'));
        $pilot = app(PilotCampaign::class);
        $pilot->create('test', 100, 200);
        foreach (array_column($input['identity'], 'external_id') as $id) {
            $input['entry_id'] = $id;
            $importer->import($source->id, $input, new DateTimeImmutable('2026-10-28'));
        }
        $this->assertSame(0, $pilot->newCount());
        $this->assertSame(1, Document::count());
        // A distinct canonical row changes the count; language/identifier rows never do.
        Document::create(['source_id' => $source->id, 'external_id' => '999', 'title' => 'Independent', 'status' => 'active']);
        $this->assertSame(1, $pilot->newCount());
    }

    public function test_stage_limit_and_unfinished_work_cannot_be_bypassed_by_a_new_run(): void
    {
        $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $pilot = app(PilotCampaign::class);
        $pilot->create('test', 100, 1);
        $manager = app(ScrapeRunManager::class);
        $run = $manager->start($source, 'full', 1, ['1']);
        $this->assertSame('test', $run->checkpoint['campaign_id']);
        try {
            $manager->start($source, 'full', 1, ['2']);
            $this->fail('Unfinished queue cannot be bypassed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unfinished', $e->getMessage());
        }
        $pilot->stop('audit_failure');
        $this->expectException(PilotStoppedException::class);
        $manager->resume($run->id);
    }

    public function test_stage_cannot_advance_early_and_request_target_caps_are_finite(): void
    {
        $pilot = app(PilotCampaign::class);
        $pilot->create('test', 4000, 200);
        $this->expectException(\RuntimeException::class);
        $pilot->advance(100);
    }

    public function test_missing_campaign_state_fails_closed(): void
    {
        app(PilotCampaign::class)->create('test', 10, 200);
        Cache::forget('lex:pilot:test');
        Http::fake(['*' => Http::response('ok', 200)]);
        try {
            (new VirtualLexHttpClient)->get('https://lex.uz/uz/docs/1');
            $this->fail('Missing state cannot recreate the allowance.');
        } catch (PilotStoppedException $e) {
            Http::assertNothingSent();
        }
    }

    public function test_command_status_is_read_only_and_stop_keeps_diagnostics(): void
    {
        Artisan::call('documents:pilot', ['action' => 'start', '--id' => 'test', '--requests' => 10, '--target' => 200]);
        Artisan::call('documents:pilot');
        $status = json_decode(Artisan::output(), true);
        $this->assertSame(0, $status['campaign']['used']);
        Artisan::call('documents:pilot', ['action' => 'stop', '--reason' => 'integrity_gate']);
        $this->assertSame('integrity_gate', app(PilotCampaign::class)->state()['stopped']['reason']);
    }

    public function test_stopped_worker_keeps_receipts_without_consuming_or_failing_other_jobs(): void
    {
        $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $pilot = app(PilotCampaign::class);
        $pilot->create('test', 10, 200);
        $run = app(ScrapeRunManager::class)->start($source, 'full', 2, ['1', '2']);
        Http::fake(['*' => Http::response('blocked', 403)]);
        Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--stop-when-empty' => true, '--max-jobs' => 4]);
        $this->assertSame('source_http_403', $pilot->state()['stopped']['reason']);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseCount('jobs', 2);
        $this->assertSame(1, (int) DB::table('jobs')->sum('attempts'));
        $this->assertSame(1, $run->items()->where('status', 'retry_wait')->count());
        $this->assertSame(1, $run->items()->where('status', 'queued')->count());
        $this->assertDatabaseCount('document_contents', 0);
    }

    public function test_request_budget_and_document_target_cannot_exceed_authorized_caps(): void
    {
        $pilot = app(PilotCampaign::class);
        foreach ([[4001, 200], [4000, 201], [0, 200]] as [$requests,$target]) {
            try {
                $pilot->create('invalid', $requests, $target);
                $this->fail('Unbounded campaign must be rejected.');
            } catch (\InvalidArgumentException $e) {
                $this->assertNull($pilot->state());
            }
        }
    }

    public function test_campaign_stop_does_not_pause_unrelated_queues(): void
    {
        $pilot = app(PilotCampaign::class);
        $pilot->create('test', 10, 200);
        $pilot->stop('operator_stop');
        $this->assertNull(app('events')->until(new Looping('database', 'default')));
        $this->assertFalse(app('queue.worker')->shouldQuit);
        $this->assertFalse(app('events')->until(new Looping('database', 'scraping')));
        $this->assertTrue(app('queue.worker')->shouldQuit);
    }
}
