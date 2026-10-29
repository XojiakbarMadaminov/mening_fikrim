<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type');
            $table->text('base_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE document_sources ADD CONSTRAINT sources_type_check CHECK (type IN ('scraper', 'manual'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sources');
    }
};
