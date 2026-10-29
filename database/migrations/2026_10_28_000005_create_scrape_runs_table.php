<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('document_sources')->restrictOnDelete();
            $table->string('mode');
            $table->string('status')->default('running');
            foreach (['processed_count', 'created_count', 'updated_count', 'failed_count'] as $column) {
                $table->integer($column)->default(0);
            }
            $table->jsonb('checkpoint')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->index(['source_id', 'status']);
            $table->index(['source_id', 'started_at']);
        });
        DB::statement("ALTER TABLE scrape_runs ADD CONSTRAINT runs_mode_check CHECK (mode IN ('full', 'daily', 'recheck'))");
        DB::statement("ALTER TABLE scrape_runs ADD CONSTRAINT runs_status_check CHECK (status IN ('running', 'completed', 'completed_with_errors', 'failed'))");
        DB::statement('ALTER TABLE scrape_runs ADD CONSTRAINT runs_counts_check CHECK (processed_count >= 0 AND created_count >= 0 AND updated_count >= 0 AND failed_count >= 0 AND created_count + updated_count <= processed_count AND failed_count <= processed_count)');
        DB::statement("ALTER TABLE scrape_runs ADD CONSTRAINT runs_completion_check CHECK (status <> 'completed' OR (failed_count = 0 AND checkpoint @> '{\"coverage_verified\":true,\"discovery_finished\":true,\"jobs_reconciled\":true,\"languages_complete\":true,\"quality_passed\":true}'::jsonb IS TRUE))");
    }

    public function down(): void
    {
        Schema::dropIfExists('scrape_runs');
    }
};
