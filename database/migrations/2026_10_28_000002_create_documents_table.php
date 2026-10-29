<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained('document_sources')->restrictOnDelete();
            $table->string('external_id')->nullable();
            $table->text('title');
            $table->string('document_type')->nullable();
            $table->string('document_number')->nullable();
            $table->string('status');
            $table->date('issued_at')->nullable();
            $table->date('published_at')->nullable();
            $table->date('effective_at')->nullable();
            $table->text('source_url')->nullable();
            $table->jsonb('metadata')->default(DB::raw("'{}'::jsonb"));
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampsTz();
            $table->unique(['source_id', 'external_id']);
            // Supports the composite FK that forbids cross-source identifiers.
            $table->unique(['id', 'source_id']);
            $table->index(['source_id', 'status', 'last_checked_at']);
            $table->index(['status', 'effective_at']);
        });
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_status_check CHECK (status IN ('active', 'pending', 'inactive'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
