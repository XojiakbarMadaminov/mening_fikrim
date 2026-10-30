<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Atomic CHECK replacement: no records, counters or historical limits are rewritten.
        DB::statement('ALTER TABLE scrape_campaigns DROP CONSTRAINT campaigns_budget_check, DROP CONSTRAINT campaigns_target_check');
        DB::statement("ALTER TABLE scrape_campaigns ADD CONSTRAINT campaigns_budget_check CHECK (
            used_http_requests BETWEEN 0 AND max_http_requests
            AND selected_source_count BETWEEN 0 AND max_source_selections
            AND ((campaign_type = 'corpus_collection' AND campaign_id = 'corpus-10000-20261029'
                AND max_http_requests BETWEEN 1 AND 120000 AND max_source_selections BETWEEN 1 AND 25000)
                OR (campaign_type <> 'corpus_collection' AND max_http_requests BETWEEN 1 AND 15000 AND max_source_selections BETWEEN 1 AND 3500)))");
        DB::statement("ALTER TABLE scrape_campaigns ADD CONSTRAINT campaigns_target_check CHECK (
            current_new_canonical_count BETWEEN 0 AND target_new_canonical
            AND ((campaign_type = 'validation' AND target_new_canonical = 0 AND stage_target = 0)
                OR (campaign_type = 'corpus_collection' AND campaign_id = 'corpus-10000-20261029'
                    AND target_new_canonical = 10000 AND stage_target BETWEEN 1 AND target_new_canonical)
                OR (campaign_type NOT IN ('validation','corpus_collection') AND target_new_canonical BETWEEN 1 AND 1000 AND stage_target BETWEEN 1 AND target_new_canonical)))");
    }

    public function down(): void
    {
        if (DB::table('scrape_campaigns')->where('max_http_requests', '>', 15000)->orWhere('max_source_selections', '>', 3500)->orWhere('target_new_canonical', '>', 1000)->exists()) {
            throw new RuntimeException('Refusing rollback: preserve the larger campaign and its durable evidence.');
        }
        DB::statement('ALTER TABLE scrape_campaigns DROP CONSTRAINT campaigns_budget_check, DROP CONSTRAINT campaigns_target_check');
        DB::statement('ALTER TABLE scrape_campaigns ADD CONSTRAINT campaigns_budget_check CHECK (used_http_requests >= 0 AND used_http_requests <= max_http_requests AND max_http_requests BETWEEN 1 AND 15000 AND selected_source_count >= 0 AND selected_source_count <= max_source_selections AND max_source_selections BETWEEN 1 AND 3500)');
        DB::statement("ALTER TABLE scrape_campaigns ADD CONSTRAINT campaigns_target_check CHECK (current_new_canonical_count >= 0 AND ((campaign_type = 'validation' AND target_new_canonical = 0 AND stage_target = 0) OR (target_new_canonical BETWEEN 1 AND 1000 AND stage_target BETWEEN 1 AND target_new_canonical)))");
    }
};
