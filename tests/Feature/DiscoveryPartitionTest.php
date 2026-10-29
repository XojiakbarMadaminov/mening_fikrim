<?php

namespace Tests\Feature;

use App\Models\DocumentSource;
use App\Services\Scraping\DiscoveryPlanner;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DiscoveryPartitionTest extends TestCase
{
    use DatabaseMigrations;

    private function createRun()
    {
        $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);

        return app(ScrapeRunManager::class)->start($source, 'full', 30, section: 'nat', from: '01.01.2020', to: '04.01.2020');
    }

    private function page(array $ids, int $total): array
    {
        return ['rows' => array_map(fn ($id) => ['id' => $id, 'listing_status' => 'Y'], $ids), 'total' => $total, 'page' => 1, 'fingerprint' => hash('sha256', json_encode($ids)), 'last_page' => true];
    }

    public function test_partition_boundaries_have_no_missing_or_repeated_calendar_day(): void
    {
        $parts = app(DiscoveryPlanner::class)->split(['section' => 'nat', 'status' => 'Y', 'from' => '01.01.2020', 'to' => '31.12.2020']);
        $this->assertSame('01.07.2020', $parts[0]['to']);
        $this->assertSame('02.07.2020', $parts[1]['from']);
        $this->assertSame('31.12.2020', $parts[1]['to']);
    }

    public function test_query_signatures_do_not_conflate_year_language_status_or_section(): void
    {
        $planner = app(DiscoveryPlanner::class);
        $base = ['section' => 'nat', 'status' => 'Y', 'from' => '01.01.2010', 'to' => '31.01.2010'];
        $hashes = [$planner->signature($base)];
        foreach (['section' => 'official', 'status' => 'R', 'lang' => '4', 'from' => '01.01.2026', 'to' => '31.01.2026', 'court_form' => 'F'] as $key => $value) {
            $hashes[] = $planner->signature(array_replace($base, [$key => $value]));
        }
        $this->assertCount(7, array_unique($hashes));
        $this->assertSame($hashes[0], $planner->signature(array_reverse($base, true)));
    }

    public function test_single_day_over_target_is_an_explicit_coverage_gap(): void
    {
        $this->expectExceptionMessage('coverage_gap');
        app(DiscoveryPlanner::class)->split(['section' => 'nat', 'from' => '01.01.2020', 'to' => '01.01.2020']);
    }

    public function test_adaptive_children_reconcile_parent_union_and_cross_interval_duplicates(): void
    {
        config(['scraping.partition_target' => 2]);
        $run = $this->createRun();
        $manager = app(ScrapeRunManager::class);
        $manager->selectPage($run->id, $this->page(['a', 'b'], 3));
        $manager->discoveryResult($run->id, ['complete' => false]);
        $this->assertSame('02.01.2020', $run->fresh()->checkpoint['segments'][0]['to']);
        $manager->selectPage($run->id, $this->page(['a', 'b'], 2));
        $manager->discoveryResult($run->id, ['complete' => true]);
        $manager->selectPage($run->id, $this->page(['b', 'c'], 2));
        $manager->discoveryResult($run->id, ['complete' => true]);
        $this->assertSame(3, $run->items()->count());
        $this->assertSame([], $run->fresh()->checkpoint['partition_parents']);
        $this->assertTrue($run->fresh()->checkpoint['discovery_finished']);
        $this->assertArrayNotHasKey('items', $run->fresh()->checkpoint);
        $this->assertSame(2, $run->fresh()->checkpoint['segments_completed']);
    }

    public function test_partition_union_mismatch_cannot_silently_advance_checkpoint(): void
    {
        config(['scraping.partition_target' => 2]);
        $run = $this->createRun();
        $manager = app(ScrapeRunManager::class);
        $manager->selectPage($run->id, $this->page(['a'], 4));
        $manager->discoveryResult($run->id, ['complete' => false]);
        $manager->selectPage($run->id, $this->page(['a'], 1));
        $manager->discoveryResult($run->id, ['complete' => true]);
        $manager->selectPage($run->id, $this->page(['b'], 1));
        $before = $run->fresh()->checkpoint;
        try {
            $manager->discoveryResult($run->id, ['complete' => true]);
            $this->fail('A parent source count gap must fail closed.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('partition union', $e->getMessage());
        }
        $this->assertEquals($before, $run->fresh()->checkpoint);
    }

    public function test_replayed_overlap_does_not_create_another_item_or_queue_receipt(): void
    {
        $run = $this->createRun();
        $page = $this->page(['a', '-a'], 2);
        app(ScrapeRunManager::class)->selectPage($run->id, $page);
        $jobs = DB::table('jobs')->count();
        app(ScrapeRunManager::class)->selectPage($run->id, $page);
        $this->assertSame(2, $run->items()->count());
        $this->assertSame($jobs, DB::table('jobs')->count());
    }

    public function test_empty_source_segment_is_reconciled_without_fabricating_items(): void
    {
        $run = $this->createRun();
        $manager = app(ScrapeRunManager::class);
        $manager->selectPage($run->id, $this->page([], 0));
        $manager->discoveryResult($run->id, ['complete' => true]);
        $this->assertSame(0, $run->items()->count());
        $this->assertTrue($run->fresh()->checkpoint['discovery_finished']);
        $this->assertFalse($run->fresh()->checkpoint['coverage_verified']);
    }
}
