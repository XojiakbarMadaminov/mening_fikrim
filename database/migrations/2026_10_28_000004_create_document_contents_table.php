<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('language', 35);
            $table->text('title');
            $table->text('content');
            $table->char('content_hash', 64);
            $table->timestampTz('last_fetched_at')->nullable();
            $table->timestampsTz();
            $table->unique(['document_id', 'language']);
        });
        DB::statement("ALTER TABLE document_contents ADD CONSTRAINT contents_hash_check CHECK (content_hash ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE document_contents ADD CONSTRAINT contents_nonempty_check CHECK (length(btrim(content)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_contents');
    }
};
