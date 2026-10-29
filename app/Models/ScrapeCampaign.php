<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScrapeCampaign extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['configuration_snapshot' => 'array', 'state' => 'array',
            'target_new_canonical' => 'integer', 'baseline_max_id' => 'integer', 'baseline_canonical_count' => 'integer',
            'current_new_canonical_count' => 'integer', 'max_source_selections' => 'integer', 'selected_source_count' => 'integer',
            'max_http_requests' => 'integer', 'used_http_requests' => 'integer', 'stage_target' => 'integer',
            'owner_generation' => 'integer', 'owner_backend_pid' => 'integer', 'owner_claimed_at' => 'immutable_datetime',
            'last_request_started_at' => 'immutable_datetime', 'started_at' => 'immutable_datetime', 'paused_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime', 'stopped_at' => 'immutable_datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(DocumentSource::class, 'source_id');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(ScrapeCampaignCheckpoint::class);
    }
}
