<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE scrape_campaigns DROP CONSTRAINT campaigns_budget_check, DROP CONSTRAINT campaigns_target_check');
        DB::statement("ALTER TABLE scrape_campaigns ADD CONSTRAINT campaigns_budget_check CHECK (
            used_http_requests BETWEEN 0 AND max_http_requests AND selected_source_count BETWEEN 0 AND max_source_selections
            AND ((campaign_type = 'corpus_collection' AND campaign_id = 'corpus-30000-20261030'
                AND max_http_requests BETWEEN 1 AND 360000 AND max_source_selections BETWEEN 1 AND 75000)
                OR (campaign_type = 'corpus_collection' AND campaign_id = 'corpus-10000-20261029'
                AND max_http_requests BETWEEN 1 AND 120000 AND max_source_selections BETWEEN 1 AND 25000)
                OR (campaign_type <> 'corpus_collection' AND max_http_requests BETWEEN 1 AND 15000 AND max_source_selections BETWEEN 1 AND 3500)))");
        DB::statement("ALTER TABLE scrape_campaigns ADD CONSTRAINT campaigns_target_check CHECK (
            current_new_canonical_count BETWEEN 0 AND target_new_canonical
            AND ((campaign_type = 'validation' AND target_new_canonical = 0 AND stage_target = 0)
                OR (campaign_type = 'corpus_collection' AND campaign_id = 'corpus-30000-20261030'
                    AND target_new_canonical = 30000 AND stage_target BETWEEN 1 AND target_new_canonical)
                OR (campaign_type = 'corpus_collection' AND campaign_id = 'corpus-10000-20261029'
                    AND target_new_canonical = 10000 AND stage_target BETWEEN 1 AND target_new_canonical)
                OR (campaign_type NOT IN ('validation','corpus_collection') AND target_new_canonical BETWEEN 1 AND 1000 AND stage_target BETWEEN 1 AND target_new_canonical)))");
    }

    public function down(): void
    {
        if (DB::table('scrape_campaigns')->where('campaign_id', 'corpus-30000-20261030')->exists()) {
            throw new RuntimeException('Refusing rollback: preserve the 30,000 campaign and its durable evidence.');
        }
        // Restore precisely the previously applied constraints; no row deletion or counter reset.
        $previous = require __DIR__.'/2026_10_29_000012_allow_bounded_corpus_collection.php';
        $previous->up();
    }
};
