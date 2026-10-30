<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentSource;
use App\Scraping\LexUz\LexHttpClient;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Fixtures\LexResponses;
use Tests\Fixtures\VirtualLexHttpClient;
use Tests\TestCase;

class P6HttpOptimizationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_same_flow_memo_is_bounded_and_fresh_flow_refetches(): void
    {
        $client = new VirtualLexHttpClient;
        Http::fake(['*' => Http::response('Original bytes', 200)]);
        $client->beginFlow('1');
        $a = $client->get('https://lex.uz/uz/docs/1');
        $b = $client->get('https://lex.uz/uz/docs/1');
        $this->assertSame($a, $b);
        $this->assertSame(1, $client->flowStatistics()['cache_hits']);
        Http::assertSentCount(1);
        $client->resetSession();
        $client->beginFlow('1');
        $client->get('https://lex.uz/uz/docs/1');
        Http::assertSentCount(2);
        $this->assertSame(0, $client->flowStatistics()['cache_hits']);
    }

    public function test_response_memo_does_not_grow_beyond_twenty_four_urls(): void
    {
        $client = new VirtualLexHttpClient;
        Http::fake(['*' => Http::response('Original bytes', 200)]);
        $client->beginFlow('1');
        for ($id = 1; $id <= 25; $id++) {
            $client->get('https://lex.uz/uz/docs/'.$id);
        }
        $client->get('https://lex.uz/uz/docs/1');
        $client->get('https://lex.uz/uz/docs/25');
        Http::assertSentCount(26);
        $this->assertSame(1, $client->flowStatistics()['cache_hits']);
    }

    public function test_review_alias_optimization_preserves_quality_identity_text_and_legal_metadata(): void
    {
        $benchmark = [];
        foreach ([false, true] as $optimized) {
            DB::beginTransaction();
            $campaign = Mockery::mock(DurableCampaign::class)->makePartial();
            // Deterministic virtual-clock benchmark only. Real DB-clock restart pacing has separate tests.
            $campaign->shouldReceive('secondsUntilNextRequest')->andReturn(0.0);
            $this->app->instance(DurableCampaign::class, $campaign);
            try {
                $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
                $campaign->create('benchmark', 100, 30, 30, ['collect_once' => $optimized]);
                $client = new VirtualLexHttpClient;
                $this->app->instance(LexHttpClient::class, $client);
                $responses = LexResponses::graph();
                foreach ($responses as $url => &$html) {
                    if (str_contains($url, '/docs/')) {
                        $html = str_replace('<div id="divCont">', '<div id="divCont"><img src="/diagram.png">', $html);
                    }
                }unset($html);
                Http::fake(array_map(fn ($html) => Http::response($html, 200, ['Content-Type' => 'text/html']), $responses));
                $run = app(ScrapeRunManager::class)->start($source, 'full', 3, ['8506802', '-8506802', '8506870']);
                $start = hrtime(true);
                for ($i = 0; $i < 4; $i++) {
                    app('queue.worker')->runNextJob('database', 'scraping', new WorkerOptions(backoff: 0, memory: 512, timeout: 900, sleep: 0, maxTries: 3, force: true));
                }
                $requestCount = count(Http::recorded());
                $text = DocumentContent::orderBy('language')->get()->map(fn ($c) => ['language' => $c->language, 'hash' => $c->content_hash, 'quality' => $c->quality_status, 'issues' => $c->quality_issues])->all();
                $benchmark[] = ['mode' => $optimized ? 'optimized_collect_once' : 'baseline_strict_alias_recheck', 'http_requests' => $requestCount, 'new_canonical' => $campaign->newCount(), 'requests_per_new_canonical' => $requestCount / max(1, $campaign->newCount()), 'virtual_elapsed_seconds' => $client->clock - 1000, 'real_local_seconds' => (hrtime(true) - $start) / 1e9, 'texts' => $text, 'canonical_anchors' => Document::pluck('external_id')->all(), 'legal_statuses' => Document::pluck('status')->all(), 'identifiers' => DB::table('document_identifiers')->orderBy('external_id')->pluck('external_id')->all(), 'skipped_aliases' => $run->items()->where('status', 'skipped_alias')->count(), 'failed_jobs' => DB::table('failed_jobs')->count()];
                $this->assertSame(1, Document::count());
                $this->assertSame(3, DocumentContent::count());
                $this->assertSame(0, DB::table('failed_jobs')->count());
            } finally {
                $campaign->release();
                DB::rollBack();
            }
        }
        $this->assertSame($benchmark[0]['texts'], $benchmark[1]['texts']);
        $this->assertSame($benchmark[0]['identifiers'], $benchmark[1]['identifiers']);
        $this->assertSame($benchmark[0]['canonical_anchors'], $benchmark[1]['canonical_anchors']);
        $this->assertSame($benchmark[0]['legal_statuses'], $benchmark[1]['legal_statuses']);
        $this->assertSame(24, $benchmark[0]['http_requests']);
        $this->assertSame(8, $benchmark[1]['http_requests']);
        $this->assertSame(2, $benchmark[1]['skipped_aliases']);
        if (($path = getenv('P6_BENCHMARK_OUTPUT')) !== false) {
            file_put_contents($path, json_encode(['kind' => 'offline synthetic reconstructions of saved source; virtual2000ms pacing; no realHTTP', 'results' => $benchmark, 'request_reduction_percent' => 100 * (1 - $benchmark[1]['http_requests'] / $benchmark[0]['http_requests'])], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        }
    }
}
