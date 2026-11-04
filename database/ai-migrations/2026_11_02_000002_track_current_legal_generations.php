<?php

use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'pgsql_ai';

    public function up(): void
    {
        $db = app(AiDatabaseGuard::class)->connection();
        $db->getSchemaBuilder()->table('document_chunk_sets', function (Blueprint $table) {
            $table->boolean('is_current')->default(false);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error_class')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->dropUnique('chunk_sets_content_profile_unique');
            $table->unique(['document_content_id', 'profile_hash', 'input_fingerprint'], 'chunk_sets_generation_unique');
        });
        $db->statement('CREATE UNIQUE INDEX chunk_sets_one_current ON document_chunk_sets(document_content_id) WHERE is_current');
        $db->statement("ALTER TABLE document_chunk_sets ADD CONSTRAINT chunk_sets_current_status CHECK (NOT is_current OR status IN ('completed','empty')), ADD CONSTRAINT chunk_sets_attempts CHECK (attempts >= 0)");
        $db->statement(<<<'SQL'
CREATE FUNCTION ai_invalidate_content_chunks() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF ROW(NEW.document_id, NEW.language, NEW.title, NEW.content, NEW.content_hash, NEW.quality_status, NEW.quality_issues, NEW.extraction_metadata)
       IS DISTINCT FROM ROW(OLD.document_id, OLD.language, OLD.title, OLD.content, OLD.content_hash, OLD.quality_status, OLD.quality_issues, OLD.extraction_metadata) THEN
        UPDATE document_chunk_sets SET is_current = false WHERE document_content_id = NEW.id AND is_current;
    END IF;
    RETURN NEW;
END $$
SQL);
        $db->statement('CREATE TRIGGER ai_invalidate_content_chunks AFTER UPDATE ON document_contents FOR EACH ROW EXECUTE FUNCTION ai_invalidate_content_chunks()');
        $db->statement(<<<'SQL'
CREATE FUNCTION ai_invalidate_document_chunks() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.source_url IS DISTINCT FROM OLD.source_url THEN
        UPDATE document_chunk_sets SET is_current = false
        WHERE document_content_id IN (SELECT id FROM document_contents WHERE document_id = NEW.id) AND is_current;
    END IF;
    RETURN NEW;
END $$
SQL);
        $db->statement('CREATE TRIGGER ai_invalidate_document_chunks AFTER UPDATE ON documents FOR EACH ROW EXECUTE FUNCTION ai_invalidate_document_chunks()');
    }

    public function down(): void
    {
        $db = app(AiDatabaseGuard::class)->connection();
        // Restoring the earlier UNIQUE would discard edition history: refuse if it exists.
        if ($db->table('document_chunk_sets')->select('document_content_id', 'profile_hash')->groupBy('document_content_id', 'profile_hash')->havingRaw('count(*) > 1')->exists()) {
            throw new LogicException('Cannot roll back while preserved multiple source generations exist.');
        }
        $db->statement('DROP TRIGGER ai_invalidate_content_chunks ON document_contents');
        $db->statement('DROP TRIGGER ai_invalidate_document_chunks ON documents');
        $db->statement('DROP FUNCTION ai_invalidate_content_chunks()');
        $db->statement('DROP FUNCTION ai_invalidate_document_chunks()');
        $db->statement('DROP INDEX chunk_sets_one_current');
        $db->statement('ALTER TABLE document_chunk_sets DROP CONSTRAINT chunk_sets_current_status, DROP CONSTRAINT chunk_sets_attempts');
        $db->getSchemaBuilder()->table('document_chunk_sets', function (Blueprint $table) {
            $table->dropUnique('chunk_sets_generation_unique');
            $table->dropColumn(['is_current', 'attempts', 'last_error_class', 'last_attempt_at']);
            $table->unique(['document_content_id', 'profile_hash'], 'chunk_sets_content_profile_unique');
        });
    }
};
