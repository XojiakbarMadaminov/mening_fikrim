<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SourceArchiveBlob extends Model
{
    protected $fillable = ['sha256', 'storage_disk', 'storage_path', 'format', 'content_type', 'file_size', 'archived_at'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'archived_at' => 'immutable_datetime'];
    }

    public function observations(): HasMany
    {
        return $this->hasMany(SourceArchiveObservation::class, 'blob_id');
    }
}
