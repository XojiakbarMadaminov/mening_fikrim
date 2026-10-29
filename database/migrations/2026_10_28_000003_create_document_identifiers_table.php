<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_identifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->index();
            $table->foreignId('source_id')->constrained('document_sources')->restrictOnDelete();
            $table->string('external_id');
            $table->string('language', 35)->nullable();
            $table->text('source_url')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['source_id', 'external_id']);
            $table->foreign(['document_id', 'source_id'])
                ->references(['id', 'source_id'])->on('documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_identifiers');
    }
};
