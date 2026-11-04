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
        $db->statement('SET max_parallel_maintenance_workers = 0');
        $concurrently = app()->runningUnitTests() ? '' : 'CONCURRENTLY ';
        $db->statement("CREATE INDEX {$concurrently}IF NOT EXISTS ai_chunks_structured_keywords ON document_chunks USING GIN (to_tsvector('simple'::regconfig, content)) WHERE quality_status = 'structured'");
        if (! $db->selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid = 'ai_chunks_structured_keywords'::regclass")->indisvalid) {
            throw new LogicException('Keyword index is invalid; inspect the isolated AI index before retrying.');
        }
    }

    public function down(): void
    {
        $db = app(AiDatabaseGuard::class)->connection();
        $concurrently = app()->runningUnitTests() ? '' : 'CONCURRENTLY ';
        $db->statement('DROP INDEX '.$concurrently.'IF EXISTS ai_chunks_structured_keywords');
    }
};
