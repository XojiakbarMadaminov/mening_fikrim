<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DocumentSource extends Model
{
    protected $fillable = ['name', 'slug', 'type', 'base_url', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'source_id');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(DocumentIdentifier::class, 'source_id');
    }

    public function scrapeRuns(): HasMany
    {
        return $this->hasMany(ScrapeRun::class, 'source_id');
    }
}
