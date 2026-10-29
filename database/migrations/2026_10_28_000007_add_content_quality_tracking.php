<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_contents', function (Blueprint $table) {
            $table->string('quality_status')->default('needs_review');
            $table->jsonb('quality_issues')->default('[]');
            $table->timestampTz('quality_checked_at')->nullable();
            $table->jsonb('extraction_metadata')->default('{}');
            $table->jsonb('pending_candidate')->nullable();
            $table->index(['quality_status', 'id']);
        });
        DB::table('document_contents')->orderBy('id')->chunkById(100, function ($contents) {
            foreach ($contents as $content) {
                $document = DB::table('documents')->find($content->document_id);
                $metadata = json_decode($document->metadata, true, flags: JSON_THROW_ON_ERROR);
                $admitted = ($metadata['validation']['languages'][$content->language]['state'] ?? null) === 'valid';
                $quality = $metadata['lex']['quality'][$content->language] ?? null;
                $identifier = DB::table('document_identifiers')->where('document_id', $content->document_id)->where('language', $content->language)->first();
                DB::table('document_contents')->where('id', $content->id)->update([
                    'quality_status' => $admitted ? 'verified' : 'needs_review',
                    'quality_issues' => json_encode($admitted ? [] : ['legacy_quality_unproven']),
                    'quality_checked_at' => $content->last_fetched_at ?? $content->updated_at,
                    'extraction_metadata' => json_encode(['source_url' => $identifier?->source_url ?? $document->source_url,
                        'quality' => $quality, 'legacy_admission' => $admitted,
                        'raw_source_retained' => false, 'content_sha256' => $content->content_hash]),
                ]);
            }
        });
        DB::statement("ALTER TABLE document_contents ADD CONSTRAINT contents_quality_status_check CHECK (quality_status IN ('verified','needs_review'))");
        DB::statement("ALTER TABLE document_contents ADD CONSTRAINT contents_quality_json_check CHECK (jsonb_typeof(quality_issues) = 'array' AND jsonb_typeof(extraction_metadata) = 'object' AND (quality_status <> 'verified' OR quality_issues = '[]'::jsonb))");
        DB::statement("ALTER TABLE document_contents ADD CONSTRAINT contents_candidate_check CHECK (pending_candidate IS NULL OR COALESCE((jsonb_typeof(pending_candidate) = 'object' AND jsonb_exists_all(pending_candidate, ARRAY['quality_status','content','content_hash','quality_issues','extraction_metadata']) AND pending_candidate->>'quality_status' = 'needs_review' AND jsonb_typeof(pending_candidate->'content') = 'string' AND jsonb_typeof(pending_candidate->'quality_issues') = 'array' AND jsonb_typeof(pending_candidate->'extraction_metadata') = 'object' AND length(btrim(pending_candidate->>'content')) > 0 AND pending_candidate->>'content_hash' ~ '^[a-f0-9]{64}$'), false))");
    }

    public function down(): void
    {
        if (! app()->environment('testing') && DB::table('document_contents')->exists()) {
            throw new RuntimeException('Refusing to discard content quality/candidate evidence outside testing.');
        }
        DB::statement('ALTER TABLE document_contents DROP CONSTRAINT contents_candidate_check, DROP CONSTRAINT contents_quality_json_check, DROP CONSTRAINT contents_quality_status_check');
        Schema::table('document_contents', function (Blueprint $table) {
            $table->dropIndex(['quality_status', 'id']);
            $table->dropColumn(['quality_status', 'quality_issues', 'quality_checked_at', 'extraction_metadata', 'pending_candidate']);
        });
    }
};
