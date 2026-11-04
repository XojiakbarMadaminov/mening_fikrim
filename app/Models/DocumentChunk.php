<?php

namespace App\Models;

use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    protected $connection = 'pgsql_ai';

    protected $guarded = ['id'];

    public function getConnection()
    {
        return app(AiDatabaseGuard::class)->connection();
    }

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function sourceContent(): BelongsTo
    {
        return $this->belongsTo(DocumentContent::class, 'document_content_id');
    }
}
