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
        $schema = $db->getSchemaBuilder();
        $schema->create('document_chunk_sets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_content_id')->constrained()->restrictOnDelete();
            $table->char('source_checksum', 64);
            $table->char('input_fingerprint', 64);
            $table->char('profile_hash', 64);
            $table->string('chunking_version', 50);
            $table->string('status', 20);
            $table->unsignedInteger('chunk_count')->default(0);
            $table->jsonb('metadata');
            $table->timestampsTz();
            $table->unique(['document_content_id', 'profile_hash'], 'chunk_sets_content_profile_unique');
        });
        $schema->create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chunk_set_id')->constrained('document_chunk_sets')->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_content_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->string('section_type', 25);
            $table->string('section_number', 100)->nullable();
            $table->text('section_title')->nullable();
            $table->string('language', 35);
            $table->text('content');
            $table->char('content_hash', 64);
            $table->text('source_url')->nullable();
            $table->char('source_checksum', 64);
            $table->string('chunking_version', 50);
            $table->string('quality_status', 30);
            $table->jsonb('metadata');
            $table->timestampsTz();
            $table->unique(['chunk_set_id', 'chunk_index']);
            $table->index(['document_id', 'language', 'chunk_index']);
            $table->index(['quality_status', 'id']);
        });
        $schema->create('ai_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $db->statement("ALTER TABLE document_chunks ADD CONSTRAINT chunks_nonempty CHECK (length(content) > 0), ADD CONSTRAINT chunks_hash CHECK (content_hash ~ '^[a-f0-9]{64}$' AND source_checksum ~ '^[a-f0-9]{64}$'), ADD CONSTRAINT chunks_index CHECK (chunk_index >= 0), ADD CONSTRAINT chunks_quality CHECK (quality_status IN ('structured', 'fallback', 'needs_review'))");
        $db->statement("ALTER TABLE document_chunk_sets ADD CONSTRAINT chunk_sets_status CHECK (status IN ('completed', 'empty', 'failed')), ADD CONSTRAINT chunk_sets_count CHECK (chunk_count >= 0)");
        // A chunk must refer to the exact content/document/language tuple, not another translation.
        $db->statement('ALTER TABLE document_contents ADD CONSTRAINT ai_content_identity UNIQUE (id, document_id, language)');
        $db->statement('ALTER TABLE document_chunks ADD CONSTRAINT chunks_source_identity FOREIGN KEY (document_content_id, document_id, language) REFERENCES document_contents (id, document_id, language)');
    }

    public function down(): void
    {
        $db = app(AiDatabaseGuard::class)->connection();
        $db->getSchemaBuilder()->dropIfExists('ai_jobs');
        $db->getSchemaBuilder()->dropIfExists('document_chunks');
        $db->getSchemaBuilder()->dropIfExists('document_chunk_sets');
        $db->statement('ALTER TABLE document_contents DROP CONSTRAINT IF EXISTS ai_content_identity');
    }
};
