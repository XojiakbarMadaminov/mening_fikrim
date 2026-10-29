<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\ScrapeRun;
use App\Services\Scraping\PilotCampaign;
use App\Services\Scraping\PilotStoppedException;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class FollowupCampaignTest extends TestCase
{
    use DatabaseMigrations;

    private function followup(): PilotCampaign
    {
        $pilot = app(PilotCampaign::class);
        $pilot->create('p5', 4000, 200);
        $pilot->stop('bounded_source_selection_budget_exhausted');
        $pilot->followup('p5', 'p51', 1800, 180);

        return $pilot;
    }

    public function test_new_followup_preserves_stopped_predecessor_and_has_separate_finite_budgets(): void
    {
        $pilot = app(PilotCampaign::class);
        $pilot->create('p5', 4000, 200);
        $pilot->stop('bounded_source_selection_budget_exhausted');
        $before = Cache::get('lex:pilot:p5');
        $pilot->followup('p5', 'p51', 1800, 180);
        $this->assertSame($before, Cache::get('lex:pilot:p5'));
        $this->assertSame(0, $pilot->state()['used']);
        $this->assertSame([20, 42], $pilot->state()['stages']);
        $this->assertSame(180, $pilot->state()['source_limit']);
        $this->expectException(\RuntimeException::class);
        $pilot->followup('p5', 'p52', 1800, 180);
    }

    public function test_access_stopped_predecessor_cannot_be_bypassed_by_followup(): void
    {
        $pilot = app(PilotCampaign::class);
        $pilot->create('p5', 4000, 200);
        $pilot->stop('source_http_429');
        $this->expectException(\RuntimeException::class);
        $pilot->followup('p5', 'p51', 1800, 180);
    }

    public function test_preceding_run_receipts_cannot_execute_under_followup(): void
    {
        $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $pilot = app(PilotCampaign::class);
        $pilot->create('p5', 4000, 200);
        $run = ScrapeRun::create(['source_id' => $source->id, 'mode' => 'full', 'started_at' => now(), 'checkpoint' => ['campaign_id' => 'p5']]);
        $pilot->stop('bounded_source_selection_budget_exhausted');
        $pilot->followup('p5', 'p51', 1800, 180);
        $this->assertFalse($pilot->allowsRun($run));
        $this->assertSame('p5', $run->fresh()->checkpoint['campaign_id']);
    }

    public function test_source_cap_is_shared_and_repeat_ids_do_not_consume_it_again(): void
    {
        $pilot = $this->followup();
        $ids = array_map('strval', range(1, 180));
        $pilot->selectSources($ids);
        $pilot->selectSources(['1', '2']);
        $this->assertCount(180, $pilot->state()['selected_ids']);
        $this->expectException(PilotStoppedException::class);
        $pilot->selectSources(['181']);
    }

    public function test_stage_advance_requires_exact_canonical_count_and_successful_audit(): void
    {
        $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $pilot = $this->followup();
        foreach (range(1, 20) as $id) {
            Document::create(['source_id' => $source->id, 'external_id' => (string) $id, 'title' => 'Synthetic stage fixture', 'status' => 'active']);
        }
        try {
            $pilot->advance(42);
            $this->fail('Audit is mandatory.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('audit', $e->getMessage());
        }
        $pilot->auditedStage([]);
        $this->assertSame(42, $pilot->advance(42)['stage']);
        $this->assertSame(20, $pilot->newCount());
    }

    public function test_failed_audit_latches_stop_and_bounded_runs_require_explicit_selection(): void
    {
        $pilot = $this->followup();
        $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        try {
            app(ScrapeRunManager::class)->start($source, 'full', 20, section: 'oliy');
            $this->fail('Uncheckpointed search must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('explicit bounded selection', $e->getMessage());
        }
        $this->expectException(PilotStoppedException::class);
        $pilot->auditedStage(['verified_hash_changed']);
    }
}
