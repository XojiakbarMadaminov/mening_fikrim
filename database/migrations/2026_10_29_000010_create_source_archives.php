<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_archive_blobs', function (Blueprint $table) {
            $table->id();
            $table->char('sha256', 64)->unique();
            $table->string('storage_disk', 100);
            $table->string('storage_path');
            $table->string('format', 10);
            $table->string('content_type', 150);
            $table->unsignedBigInteger('file_size');
            $table->timestampTz('archived_at');
            $table->timestampsTz();
            $table->unique(['storage_disk', 'storage_path']);
        });
        Schema::create('source_archive_observations', function (Blueprint $table) {
            $table->id();
            $table->char('observation_key', 64)->unique();
            $table->uuid('capture_key')->index();
            $table->foreignId('blob_id')->nullable()->constrained('source_archive_blobs')->restrictOnDelete();
            $table->foreignId('document_source_id')->constrained('document_sources')->restrictOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->string('source_external_id')->nullable();
            $table->string('language', 35)->nullable();
            $table->text('source_url');
            $table->char('observed_sha256', 64);
            $table->unsignedBigInteger('file_size');
            $table->string('format', 10);
            $table->string('content_type', 150);
            $table->string('archive_status', 30);
            $table->string('failure_reason', 150)->nullable();
            $table->timestampTz('fetched_at');
            $table->jsonb('context')->default('{}');
            $table->timestampsTz();
            $table->index(['document_source_id', 'document_id', 'archive_status'], 'archive_observations_source_document_status');
        });
        DB::statement("ALTER TABLE source_archive_blobs ADD CONSTRAINT source_archive_blob_shape CHECK (sha256 ~ '^[a-f0-9]{64}$' AND format IN ('html','pdf') AND file_size > 0)");
        DB::statement("ALTER TABLE source_archive_observations ADD CONSTRAINT source_archive_observation_shape CHECK (observed_sha256 ~ '^[a-f0-9]{64}$' AND format IN ('html','pdf') AND archive_status IN ('archived','disabled','privacy_blocked','failed') AND jsonb_typeof(context) = 'object' AND (archive_status <> 'archived' OR blob_id IS NOT NULL))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION validate_source_archive_observation() RETURNS trigger AS $$
            BEGIN
                IF NEW.document_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM documents WHERE id = NEW.document_id AND source_id = NEW.document_source_id
                ) THEN
                    RAISE EXCEPTION 'Archive observation document/source mismatch' USING ERRCODE = '23514';
                END IF;
                IF NEW.blob_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM source_archive_blobs WHERE id = NEW.blob_id
                    AND sha256 = NEW.observed_sha256 AND file_size = NEW.file_size AND format = NEW.format
                ) THEN
                    RAISE EXCEPTION 'Archive observation/blob mismatch' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER source_archive_observation_reference_check BEFORE INSERT OR UPDATE
            ON source_archive_observations FOR EACH ROW EXECUTE FUNCTION validate_source_archive_observation();
            SQL);
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('source_archive_observations')->exists()) {
            throw new RuntimeException('Refusing to discard source archive provenance outside testing.');
        }
        Schema::dropIfExists('source_archive_observations');
        DB::statement('DROP FUNCTION IF EXISTS validate_source_archive_observation()');
        Schema::dropIfExists('source_archive_blobs');
    }
};
