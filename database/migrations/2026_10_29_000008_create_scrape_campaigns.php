<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_id', 60)->unique();
            $table->foreignId('source_id')->constrained('document_sources')->restrictOnDelete();
            $table->string('campaign_type')->default('corpus_pilot');
            $table->string('status')->default('running');
            $table->unsignedInteger('target_new_canonical');
            $table->unsignedBigInteger('baseline_max_id');
            $table->unsignedInteger('baseline_canonical_count');
            $table->unsignedInteger('current_new_canonical_count')->default(0);
            $table->unsignedInteger('max_source_selections');
            $table->unsignedInteger('selected_source_count')->default(0);
            $table->unsignedInteger('max_http_requests');
            $table->unsignedInteger('used_http_requests')->default(0);
            $table->unsignedInteger('stage_target');
            $table->unsignedInteger('owner_generation')->default(0);
            $table->uuid('owner_token')->nullable();
            $table->unsignedInteger('owner_backend_pid')->nullable();
            $table->timestampTz('owner_claimed_at')->nullable();
            $table->timestampTz('last_request_started_at', 6)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('paused_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('stopped_at')->nullable();
            $table->text('stop_reason')->nullable();
            $table->jsonb('configuration_snapshot');
            $table->jsonb('state')->default('{}');
            $table->timestampsTz();
            $table->index(['source_id', 'id']);
        });
        DB::statement("ALTER TABLE scrape_campaigns ADD CONSTRAINT campaigns_status_check CHECK (status IN ('running','pausing','paused','completed','stopped','failed'))");
        DB::statement('ALTER TABLE scrape_campaigns ADD CONSTRAINT campaigns_budget_check CHECK (used_http_requests >= 0 AND used_http_requests <= max_http_requests AND max_http_requests BETWEEN 1 AND 15000 AND selected_source_count >= 0 AND selected_source_count <= max_source_selections AND max_source_selections BETWEEN 1 AND 3500)');
        DB::statement("ALTER TABLE scrape_campaigns ADD CONSTRAINT campaigns_target_check CHECK (current_new_canonical_count >= 0 AND ((campaign_type = 'validation' AND target_new_canonical = 0 AND stage_target = 0) OR (target_new_canonical BETWEEN 1 AND 1000 AND stage_target BETWEEN 1 AND target_new_canonical)))");
        DB::statement("CREATE UNIQUE INDEX campaigns_one_open_source ON scrape_campaigns(source_id) WHERE status IN ('running','pausing','paused')");

        Schema::create('scrape_campaign_checkpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrape_campaign_id')->constrained('scrape_campaigns')->restrictOnDelete();
            $table->string('name', 60);
            $table->jsonb('bounds');
            $table->jsonb('state');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('owner_generation');
            $table->timestampsTz();
            $table->unique(['scrape_campaign_id', 'name']);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('scrape_campaigns')->exists()) {
            throw new RuntimeException('Refusing to delete durable campaign evidence outside testing.');
        }
        Schema::dropIfExists('scrape_campaign_checkpoints');
        Schema::dropIfExists('scrape_campaigns');
    }
};
