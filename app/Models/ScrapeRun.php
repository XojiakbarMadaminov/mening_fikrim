<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapeRun extends Model
{
    public $timestamps = false;

    protected $fillable = ['source_id', 'mode', 'status', 'processed_count', 'created_count', 'updated_count', 'failed_count', 'checkpoint', 'last_error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['checkpoint' => 'array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'processed_count' => 'integer', 'created_count' => 'integer', 'updated_count' => 'integer', 'failed_count' => 'integer'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DocumentSource::class, 'source_id');
    }

    public function canComplete(): bool
    {
        foreach (['coverage_verified', 'discovery_finished', 'jobs_reconciled', 'languages_complete', 'quality_passed'] as $gate) {
            if (($this->checkpoint[$gate] ?? false) !== true) {
                return false;
            }
        }

        return $this->failed_count === 0 && ! $this->items()->whereNotIn('status', ['imported', 'unchanged', 'skipped_alias'])->exists();
    }

    public function items(): HasMany
    {
        return $this->hasMany(ScrapeRunItem::class);
    }
}
