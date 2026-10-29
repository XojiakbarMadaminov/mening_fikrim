<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentIdentifier extends Model
{
    protected $fillable = ['document_id', 'source_id', 'external_id', 'language', 'source_url', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DocumentSource::class, 'source_id');
    }
}
