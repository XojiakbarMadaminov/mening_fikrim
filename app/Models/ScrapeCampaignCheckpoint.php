<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScrapeCampaignCheckpoint extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['bounds' => 'array', 'state' => 'array', 'version' => 'integer', 'owner_generation' => 'integer'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(ScrapeCampaign::class, 'scrape_campaign_id');
    }
}
