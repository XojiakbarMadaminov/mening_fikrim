<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceArchiveObservation extends Model
{
    protected $fillable = ['observation_key', 'capture_key', 'blob_id', 'document_source_id', 'document_id', 'source_external_id', 'language',
        'source_url', 'observed_sha256', 'file_size', 'format', 'content_type', 'archive_status', 'failure_reason', 'fetched_at', 'context'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'fetched_at' => 'immutable_datetime', 'context' => 'array'];
    }

    public function blob(): BelongsTo
    {
        return $this->belongsTo(SourceArchiveBlob::class, 'blob_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
