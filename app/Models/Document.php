<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    /** Aggregate of stored representations, with known missing required languages kept visible. */
    public function getQualityStatusAttribute(): string
    {
        $contents = $this->contents;
        if ($contents->isEmpty()) {
            return 'extraction_failed';
        }
        $availability = $this->metadata['language_availability'] ?? [];
        $required = $availability === [] ? ($this->metadata['validation']['required_languages'] ?? [])
            : array_keys(array_filter($availability, fn ($entry) => $entry['status'] === 'available'));
        if (array_diff($required, $contents->pluck('language')->all()) !== []
            || collect($this->metadata['validation']['languages'] ?? [])->contains(fn ($entry, $language) => in_array($language, $required, true) && ($entry['quality_status'] ?? null) === 'extraction_failed')
            || $contents->contains(fn ($content) => $content->quality_status !== 'verified' || $content->pending_candidate !== null)) {
            return 'needs_review';
        }

        return 'verified';
    }

    /** Availability uncertainty is coverage uncertainty, not extracted-content quality. */
    public function getLanguageCoverageStatusAttribute(): string
    {
        if ($this->contents->isEmpty()) {
            return 'no_usable_content';
        }
        $availability = $this->metadata['language_availability'] ?? [];
        $available = array_keys(array_filter($availability, fn ($entry) => $entry['status'] === 'available'));
        if (array_diff($available, $this->contents->pluck('language')->all()) !== []) {
            return 'incomplete';
        }
        if (collect($this->metadata['validation']['languages'] ?? [])->contains(fn ($entry, $language) => in_array($language, $available, true) && ($entry['quality_status'] ?? null) === 'extraction_failed')) {
            return 'incomplete';
        }
        if ($availability === [] || collect($availability)->contains(fn ($entry) => $entry['status'] === 'unknown')) {
            return 'unknown';
        }

        return 'complete';
    }

    protected $fillable = ['source_id', 'external_id', 'title', 'document_type', 'document_number', 'status', 'issued_at', 'published_at', 'effective_at', 'source_url', 'metadata', 'last_checked_at'];

    protected function casts(): array
    {
        return ['status' => DocumentStatus::class, 'metadata' => 'array', 'issued_at' => 'immutable_date', 'published_at' => 'immutable_date', 'effective_at' => 'immutable_date', 'last_checked_at' => 'immutable_datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DocumentSource::class, 'source_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(DocumentIdentifier::class);
    }

    public function contents(): HasMany
    {
        return $this->hasMany(DocumentContent::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Active);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Pending);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Inactive);
    }
}
