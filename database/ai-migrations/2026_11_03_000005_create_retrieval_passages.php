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
        $schema->table('document_chunk_sets', function (Blueprint $table) {
            $table->string('layer', 20)->default('source');
            $table->foreignId('source_generation_id')->nullable()->constrained('document_chunk_sets')->restrictOnDelete();
            $table->unique(['id', 'document_content_id'], 'chunk_sets_id_content_unique');
        });
        $db->statement('DROP INDEX chunk_sets_one_current');
        $db->statement('CREATE UNIQUE INDEX chunk_sets_one_current ON document_chunk_sets(document_content_id,layer) WHERE is_current');
        $db->statement("ALTER TABLE document_chunk_sets ADD CONSTRAINT chunk_sets_layer CHECK ((layer='source' AND source_generation_id IS NULL) OR (layer='retrieval' AND source_generation_id IS NOT NULL))");
        $schema->create('retrieval_passages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chunk_set_id')->constrained('document_chunk_sets')->restrictOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->foreignId('document_content_id')->constrained()->restrictOnDelete();
            $table->string('language', 35);
            $table->unsignedInteger('passage_index');
            $table->text('body');
            $table->char('body_hash', 64);
            $table->unsignedBigInteger('start_byte');
            $table->unsignedBigInteger('end_byte');
            $table->unsignedBigInteger('start_char');
            $table->unsignedBigInteger('end_char');
            $table->string('suitability', 20);
            $table->jsonb('lineage');
            $table->jsonb('metadata');
            $table->timestampsTz();
            $table->unique(['chunk_set_id', 'passage_index']);
            $table->index(['document_content_id', 'passage_index']);
            $table->foreign(['chunk_set_id', 'document_content_id'], 'passages_generation_identity')->references(['id', 'document_content_id'])->on('document_chunk_sets');
            $table->foreign(['document_content_id', 'document_id', 'language'], 'passages_source_identity')->references(['id', 'document_id', 'language'])->on('document_contents');
        });
        $db->statement("ALTER TABLE retrieval_passages ADD CONSTRAINT passages_body CHECK (char_length(body) BETWEEN 1 AND 32000), ADD CONSTRAINT passages_hash CHECK (body_hash ~ '^[a-f0-9]{64}$'), ADD CONSTRAINT passages_offsets CHECK (end_byte>start_byte AND end_char>start_char), ADD CONSTRAINT passages_suitability CHECK (suitability IN ('eligible','review','blocked')), ADD CONSTRAINT passages_lineage CHECK (jsonb_typeof(lineage)='array' AND jsonb_array_length(lineage)>0)");
    }

    public function down(): void
    {
        $db = app(AiDatabaseGuard::class)->connection();
        if ($db->table('document_chunk_sets')->where('layer', 'retrieval')->exists()) {
            throw new LogicException('Retrieval generation history exists; refusing destructive rollback.');
        }
        $db->getSchemaBuilder()->dropIfExists('retrieval_passages');
        $db->statement('ALTER TABLE document_chunk_sets DROP CONSTRAINT chunk_sets_layer');
        $db->statement('DROP INDEX chunk_sets_one_current');
        $db->statement('CREATE UNIQUE INDEX chunk_sets_one_current ON document_chunk_sets(document_content_id) WHERE is_current');
        $db->getSchemaBuilder()->table('document_chunk_sets', function (Blueprint $table) {
            $table->dropForeign(['source_generation_id']);
            $table->dropUnique('chunk_sets_id_content_unique');
            $table->dropColumn(['layer', 'source_generation_id']);
        });
    }
};
