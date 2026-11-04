<?php

use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    protected $connection = 'pgsql_ai';

    public $withinTransaction = false;

    public function up(): void
    {
        $db = app(AiDatabaseGuard::class)->connection();
        $concurrently = app()->runningUnitTests() ? '' : 'CONCURRENTLY ';
        $db->statement('SET max_parallel_maintenance_workers = 0');
        $db->statement("CREATE INDEX {$concurrently}IF NOT EXISTS ai_chunks_all_keywords ON document_chunks USING GIN (to_tsvector('simple'::regconfig, content))");
        if (! $db->selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'ai_chunks_all_keywords'::regclass")->indisvalid) {
            throw new LogicException('Full-quality lexical index is invalid; inspect isolated AI index before retrying.');
        }
        // Replace the earlier partial index only after all-quality indexing is valid.
        $db->statement('DROP INDEX '.$concurrently.'IF EXISTS ai_chunks_structured_keywords');
    }

    public function down(): void
    {
        $db = app(AiDatabaseGuard::class)->connection();
        $concurrently = app()->runningUnitTests() ? '' : 'CONCURRENTLY ';
        $db->statement("CREATE INDEX {$concurrently}IF NOT EXISTS ai_chunks_structured_keywords ON document_chunks USING GIN (to_tsvector('simple'::regconfig, content)) WHERE quality_status = 'structured'");
        $db->statement('DROP INDEX '.$concurrently.'IF EXISTS ai_chunks_all_keywords');
    }
};
