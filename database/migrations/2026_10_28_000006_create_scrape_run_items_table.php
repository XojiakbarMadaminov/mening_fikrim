<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scrape_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scrape_run_id')->constrained('scrape_runs')->cascadeOnDelete();
            $table->string('source_external_id');
            $table->foreignId('document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->string('status')->default('discovered');
            $table->unsignedInteger('attempts')->default(0);
            $table->string('listing_status')->nullable();
            $table->string('action')->nullable();
            $table->jsonb('review_reasons')->nullable();
            $table->jsonb('language_results')->nullable();
            $table->jsonb('outcome')->nullable();
            $table->jsonb('discovery_signatures')->default('[]');
            // The queue row may disappear normally; this is a receipt, deliberately not a FK.
            $table->unsignedBigInteger('queue_job_id')->nullable();
            $table->uuid('dispatch_token')->nullable();
            $table->timestampTz('available_at')->nullable();
            $table->timestampTz('lease_expires_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->unique(['scrape_run_id', 'source_external_id']);
            $table->index(['scrape_run_id', 'status']);
            $table->index(['scrape_run_id', 'document_id']);
        });
        DB::statement("ALTER TABLE scrape_run_items ADD CONSTRAINT items_status_check CHECK (status IN ('discovered','queued','processing','retry_wait','imported','unchanged','review','failed','skipped_alias'))");
        DB::statement('ALTER TABLE scrape_run_items ADD CONSTRAINT items_attempts_check CHECK (attempts >= 0)');
        DB::statement("ALTER TABLE scrape_run_items ADD CONSTRAINT items_terminal_check CHECK ((status IN ('imported','unchanged','review','failed','skipped_alias')) = (finished_at IS NOT NULL))");
        DB::statement("ALTER TABLE scrape_run_items ADD CONSTRAINT items_document_check CHECK (status NOT IN ('imported','unchanged','skipped_alias') OR document_id IS NOT NULL)");
        DB::statement("CREATE INDEX items_stale_processing ON scrape_run_items(lease_expires_at) WHERE status = 'processing'");

        // P2 evidence remains in docs/p2 and failed_jobs. Move its bounded ledger without
        // inventing attempts or changing its recorded finish times/content/document IDs.
        DB::table('scrape_runs')->orderBy('id')->chunkById(100, function ($runs) {
            foreach ($runs as $run) {
                $cp = json_decode($run->checkpoint ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                if (($cp['version'] ?? null) !== 2) {
                    continue;
                }
                foreach ($cp['items'] ?? [] as $id => $item) {
                    $result = $cp['outcomes'][$id] ?? null;
                    $state = $result === null ? 'discovered' : match ($result['state']) {
                        'valid' => ($result['action'] === 'unchanged' ? 'unchanged' : 'imported'),
                        'review' => 'review', default => 'failed',
                    };
                    DB::table('scrape_run_items')->insert([
                        'scrape_run_id' => $run->id, 'source_external_id' => (string) $id,
                        'document_id' => $result['document_id'] ?? null, 'status' => $state,
                        'listing_status' => $item['listing_status'] ?? null, 'action' => $result['action'] ?? null,
                        'language_results' => isset($result['languages']) ? json_encode($result['languages']) : null,
                        'review_reasons' => $state === 'review' ? json_encode(['languages' => $result['languages'] ?? [], 'quality' => $result['quality'] ?? []]) : null,
                        'outcome' => $result === null ? null : json_encode($result), 'last_error' => $result['error'] ?? null,
                        'finished_at' => $result === null ? null : $run->finished_at,
                        'created_at' => $run->started_at, 'updated_at' => $run->finished_at ?? $run->started_at,
                    ]);
                }
                $cp['legacy_failed_count'] = $run->failed_count;
                $cp['legacy_checkpoint_sha256'] = hash('sha256', $run->checkpoint);
                $cp['version'] = 3;
                unset($cp['items'], $cp['outcomes']);
                DB::table('scrape_runs')->where('id', $run->id)->update(['checkpoint' => json_encode($cp),
                    'failed_count' => DB::table('scrape_run_items')->where('scrape_run_id', $run->id)->where('status', 'failed')->count()]);
            }
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION check_scrape_run_ledger() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE target_id bigint; r scrape_runs%ROWTYPE; n bigint; bad bigint;
BEGIN
    IF TG_TABLE_NAME = 'scrape_runs' THEN target_id := NEW.id;
    ELSIF TG_OP = 'DELETE' THEN target_id := OLD.scrape_run_id;
    ELSE target_id := NEW.scrape_run_id; END IF;
    SELECT * INTO r FROM scrape_runs WHERE id = target_id;
    IF r.status = 'completed' AND COALESCE((r.checkpoint->>'version')::int,0) >= 3 THEN
        SELECT count(*), count(*) FILTER (WHERE status NOT IN ('imported','unchanged','skipped_alias') OR COALESCE((outcome->>'complete')::boolean,false) = false)
        INTO n,bad FROM scrape_run_items WHERE scrape_run_id = target_id;
        IF bad > 0 OR r.processed_count <> n OR r.failed_count <> 0 THEN
            RAISE EXCEPTION 'completed run has unresolved or unreconciled durable items' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NULL;
END $$;
CREATE CONSTRAINT TRIGGER runs_ledger_completion AFTER INSERT OR UPDATE ON scrape_runs DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_scrape_run_ledger();
CREATE CONSTRAINT TRIGGER items_ledger_completion AFTER INSERT OR UPDATE OR DELETE ON scrape_run_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION check_scrape_run_ledger();
SQL);
    }

    public function down(): void
    {
        // Development rollback is intentionally refused when durable evidence exists.
        if (! app()->environment('testing') && DB::table('scrape_run_items')->exists()) {
            throw new RuntimeException('Refusing to discard durable run evidence outside testing.');
        }
        DB::statement('DROP TRIGGER IF EXISTS runs_ledger_completion ON scrape_runs');
        Schema::dropIfExists('scrape_run_items');
        DB::statement('DROP FUNCTION IF EXISTS check_scrape_run_ledger()');
    }
};
