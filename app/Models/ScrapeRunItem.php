<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ScrapeRunItem extends Model
{
    public const TERMINAL = ['imported', 'unchanged', 'review', 'failed', 'skipped_alias'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'discovered', 'attempts' => 0, 'discovery_signatures' => '[]'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'review_reasons' => 'array', 'language_results' => 'array', 'outcome' => 'array', 'discovery_signatures' => 'array',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime', 'available_at' => 'immutable_datetime', 'lease_expires_at' => 'immutable_datetime'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(ScrapeRun::class, 'scrape_run_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function terminal(): bool
    {
        return in_array($this->status, self::TERMINAL, true);
    }

    /** Caller holds a row lock; requeueing failed requires an explicit resume. */
    public function moveTo(string $status, array $attributes = []): void
    {
        $allowed = match ($this->status) {
            'discovered' => ['queued'],
            'queued' => ['processing', 'failed', 'queued'],
            'processing' => ['imported', 'unchanged', 'review', 'failed', 'retry_wait', 'skipped_alias', 'queued', 'processing'],
            'retry_wait' => ['processing', 'queued', 'failed'],
            'failed' => ['queued'],
            default => [],
        };
        if (! in_array($status, $allowed, true)) {
            throw new LogicException('Invalid run item transition '.$this->status.' -> '.$status);
        }
        $this->fill($attributes + ['status' => $status,
            'finished_at' => in_array($status, self::TERMINAL, true) ? now() : null]);
        $this->save();
    }
}
