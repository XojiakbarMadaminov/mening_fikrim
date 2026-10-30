<?php

namespace Tests\Feature;

use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzScraper;
use App\Services\Scraping\BoundedDiscovery;
use App\Services\Scraping\PilotCampaign;
use App\Services\Scraping\PilotStoppedException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\LexResponses;
use Tests\Fixtures\VirtualLexHttpClient;
use Tests\TestCase;

class BoundedDiscoveryTest extends TestCase
{
    use DatabaseMigrations;

    private function segment(string $from = '01.01.2020', string $to = '04.01.2020'): array
    {
        return ['section' => 'oliy', 'status' => 'Y', 'from' => $from, 'to' => $to];
    }

    private function fakeTree(bool $duplicate = false): void
    {
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        $scraper = app(LexUzScraper::class);
        Http::fake([
            $scraper->searchUrl($this->segment()) => Http::response(LexResponses::listing(['1'], 4, 'next')),
            $scraper->searchUrl($this->segment('01.01.2020', '02.01.2020')) => Http::response(LexResponses::listing(['1', '2'], 2)),
            $scraper->searchUrl($this->segment('03.01.2020', '04.01.2020')) => Http::response(LexResponses::listing($duplicate ? ['2', '3'] : ['3', '4'], 2)),
        ]);
    }

    public function test_incomplete_parent_splits_inclusive_children_and_reconciles_unique_union(): void
    {
        $this->fakeTree();
        $result = app(BoundedDiscovery::class)->collect('test', $this->segment(), 1);
        $this->assertTrue($result['complete']);
        $this->assertCount(3, $result['nodes']);
        $this->assertSame(['1', '2', '3', '4'], array_column($result['rows'], 'id'));
        $this->assertSame('03.01.2020', array_values($result['nodes'])[2]['query']['from']);
        app(BoundedDiscovery::class)->collect('test', $this->segment(), 1);
        Http::assertSentCount(3); // Completed partitions are not downloaded again.
    }

    public function test_single_day_overflow_and_maximum_depth_remain_incomplete(): void
    {
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake(['*' => Http::response(LexResponses::listing(['1'], 4, 'next'))]);
        $day = app(BoundedDiscovery::class)->collect('day', $this->segment('01.01.2020', '01.01.2020'), 1);
        $this->assertFalse($day['complete']);
        $this->assertSame('single_day_overflow', $day['root']['reason']);
        $depth = app(BoundedDiscovery::class)->collect('depth', $this->segment(), 1, 0);
        $this->assertFalse($depth['complete']);
        $this->assertSame('maximum_depth', $depth['root']['reason']);
    }

    public function test_explicit_single_day_budget_enumerates_397_results_and_reuses_checkpoint(): void
    {
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        $responses = Http::sequence();
        foreach (array_chunk(range(1, 397), 20) as $index => $ids) {
            $responses->push(LexResponses::listing(array_map('strval', $ids), 397, $index === 19 ? null : 'next'));
        }
        Http::fake(['*' => $responses]);
        $query = $this->segment('30.12.2021', '30.12.2021');
        $result = app(BoundedDiscovery::class)->collect('large-day', $query, singleDayPageCap: 64);
        $this->assertTrue($result['complete']);
        $this->assertCount(397, $result['rows']);
        $this->assertSame(20, $result['root']['enumeration']['pages']);
        $again = app(BoundedDiscovery::class)->collect('large-day', $query, singleDayPageCap: 64);
        $this->assertSame($result['rows'], $again['rows']);
        Http::assertSentCount(20);
    }

    public function test_even_extended_single_day_budget_keeps_overflow_explicit(): void
    {
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake(['*' => Http::response(LexResponses::listing(array_map('strval', range(1, 20)), 2000, 'next'))]);
        $result = app(BoundedDiscovery::class)->collect('too-large-day', $this->segment('01.01.2020', '01.01.2020'), singleDayPageCap: 64);
        $this->assertFalse($result['complete']);
        $this->assertSame('single_day_overflow', $result['root']['reason']);
        $this->assertSame(2000, $result['root']['total']);
        Http::assertSentCount(1);
    }

    public function test_partition_cap_is_finite_and_never_produces_false_completion(): void
    {
        $this->fakeTree();
        $result = app(BoundedDiscovery::class)->collect('cap', $this->segment(), 1, 10, 1);
        $this->assertFalse($result['complete']);
        $this->assertCount(1, $result['nodes']);
        Http::assertSentCount(1);
    }

    public function test_interrupted_prefix_replays_safely_and_completed_children_are_retained(): void
    {
        $this->fakeTree();
        $calls = 0;
        try {
            app(BoundedDiscovery::class)->collect('resume', $this->segment(), 1, afterPage: function () use (&$calls) {
                if (++$calls === 3) {
                    throw new \RuntimeException('controlled interruption');
                }
            });
            $this->fail('Expected controlled interruption.');
        } catch (\RuntimeException $e) {
            $this->assertSame('controlled interruption', $e->getMessage());
        }
        $result = app(BoundedDiscovery::class)->collect('resume', $this->segment(), 1);
        $this->assertTrue($result['complete']);
        Http::assertSentCount(4); // only unfinished child replayed
        $this->assertCount(4, $result['rows']);
    }

    public function test_budget_exhaustion_preserves_checkpoint_and_stops_further_requests(): void
    {
        app(PilotCampaign::class)->create('budget', 1, 20);
        $this->fakeTree();
        $this->expectException(PilotStoppedException::class);
        try {
            app(BoundedDiscovery::class)->collect('budget', $this->segment(), 1);
        } finally {
            $this->assertSame(1, app(PilotCampaign::class)->state()['used']);
            Http::assertSentCount(1);
        }
    }

    public function test_duplicate_ids_across_date_children_cannot_satisfy_parent_count(): void
    {
        $this->fakeTree(true);
        $result = app(BoundedDiscovery::class)->collect('duplicate', $this->segment(), 1);
        $this->assertFalse($result['complete']);
        $this->assertCount(3, $result['rows']);
    }

    public function test_recursive_subdivision_has_a_complete_leaf_for_every_inclusive_day(): void
    {
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake(function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $from = (int) substr($query['from'], 0, 2);
            $to = (int) substr($query['to'], 0, 2);

            return Http::response(LexResponses::listing([(string) $from], $to - $from + 1, $from === $to ? null : 'next'));
        });
        $result = app(BoundedDiscovery::class)->collect('recursive', $this->segment(), 1);
        $this->assertTrue($result['complete']);
        $this->assertCount(7, $result['nodes']);
        $this->assertSame(['1', '2', '3', '4'], array_column($result['rows'], 'id'));
        Http::assertSentCount(7);
    }

    public function test_interrupted_committed_prefix_cannot_silently_change_on_replay(): void
    {
        $this->app->instance(LexHttpClient::class, new VirtualLexHttpClient);
        Http::fake(['*' => Http::sequence()->push(LexResponses::listing(['1'], 1))->push(LexResponses::listing(['2'], 1))]);
        try {
            app(BoundedDiscovery::class)->collect('drift', $this->segment(), afterPage: fn () => throw new \RuntimeException('interrupt'));
        } catch (\RuntimeException $e) {
            $this->assertSame('interrupt', $e->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('committed prefix or total drifted');
        app(BoundedDiscovery::class)->collect('drift', $this->segment());
    }
}
