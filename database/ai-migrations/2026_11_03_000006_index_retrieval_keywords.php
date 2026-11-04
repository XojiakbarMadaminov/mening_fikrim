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
        $db->statement('SET max_parallel_maintenance_workers=0');
        $db->statement("SET maintenance_work_mem='32MB'");
        // P3 pilot: the old GIN indexes a different physical relation. Body scans took ~4.9s.
        $db->statement("CREATE INDEX {$concurrently}IF NOT EXISTS ai_passages_keywords ON retrieval_passages USING GIN (to_tsvector('simple'::regconfig,body))");
        if (! $db->selectOne("SELECT indisvalid FROM pg_index WHERE indexrelid='ai_passages_keywords'::regclass")->indisvalid) {
            throw new LogicException('Retrieval lexical index is invalid; inspect isolated AI index before retrying.');
        }
    }

    public function down(): void
    {
        $db = app(AiDatabaseGuard::class)->connection();
        $concurrently = app()->runningUnitTests() ? '' : 'CONCURRENTLY ';
        $db->statement('DROP INDEX '.$concurrently.'IF EXISTS ai_passages_keywords');
    }
};
