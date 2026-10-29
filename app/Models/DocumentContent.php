<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentContent extends Model
{
    protected $fillable = ['document_id', 'language', 'title', 'content', 'content_hash', 'last_fetched_at', 'quality_status', 'quality_issues', 'quality_checked_at', 'extraction_metadata', 'pending_candidate'];

    protected $attributes = ['quality_status' => 'needs_review', 'quality_issues' => '[]', 'extraction_metadata' => '{}'];

    protected function casts(): array
    {
        return ['last_fetched_at' => 'immutable_datetime', 'quality_checked_at' => 'immutable_datetime', 'quality_issues' => 'array', 'extraction_metadata' => 'array', 'pending_candidate' => 'array'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
