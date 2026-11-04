<?php

namespace App\Services\Scraping;

use App\Jobs\ImportDocumentJob;
use App\Models\DocumentIdentifier;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class RunItemTracker
{
    public function retryAllowed(ScrapeRunItem $item): bool
    {
        $control = $item->outcome['retry_control'] ?? [];
        $base = (int) ($control['base_attempts'] ?? 0);
        $limit = max(1, min(3, (int) ($control['limit'] ?? config('scraping.max_retries'))));

        return $item->attempts < $base + $limit;
    }

    private function exhausted(ScrapeRunItem $item): void
    {
        $this->finish($item->scrape_run_id, $item->source_external_id, [
            ...($item->outcome ?? []), 'state' => 'failed', 'complete' => false,
            'failure_code' => 'SOURCE_RETRY_LIMIT_EXCEEDED', 'failure_classification' => 'retry_exhausted',
            'error' => 'SOURCE_RETRY_LIMIT_EXCEEDED: cumulative source attempts cannot be reset by redispatch.',
        ], $item->dispatch_token);
    }

    /** Must run inside the discovery/recovery transaction. The receipt and queue row commit together. */
    public function enqueue(ScrapeRunItem $item): bool
    {
        if (! $this->retryAllowed($item)) {
            if (! $item->terminal()) {
                $this->exhausted($item);
            }

            return false;
        }
        $token = (string) Str::uuid();
        $job = new ImportDocumentJob($item->run->source_id, $item->source_external_id, null, $item->scrape_run_id, $item->listing_status, $item->id, $token);
        $id = app(ScrapeRunManager::class)->push($job);
        $item->moveTo('queued', ['dispatch_token' => $token, 'queue_job_id' => $id, 'available_at' => now(), 'lease_expires_at' => null]);

        return true;
    }

    /** A dispatch token fences an old delivery after explicit stale recovery. */
    public function claim(int $id, ?string $token, ?string $queueId): array
    {
        return DB::transaction(function () use ($id, $token, $queueId) {
            ScrapeRun::lockForUpdate()->findOrFail(ScrapeRunItem::findOrFail($id)->scrape_run_id);
            $item = ScrapeRunItem::lockForUpdate()->findOrFail($id);
            if ($item->terminal() || $item->dispatch_token !== $token || ($queueId !== null && $item->queue_job_id !== null && (string) $item->queue_job_id !== $queueId)) {
                return ['item' => null];
            }
            if ($item->status === 'processing' && $item->lease_expires_at?->isFuture()) {
                return ['item' => null, 'wait' => max(1, (int) now()->diffInSeconds($item->lease_expires_at))];
            }
            if (! $this->retryAllowed($item)) {
                $this->exhausted($item);

                return ['item' => null];
            }
            $item->moveTo('processing', ['attempts' => $item->attempts + 1, 'queue_job_id' => $queueId ?? $item->queue_job_id, 'started_at' => $item->started_at ?? now(),
                'lease_expires_at' => now()->addSeconds(config('scraping.job_timeout') + 30)]);

            return ['item' => $item, 'queue_wait_seconds' => max(0, now()->diffInSeconds($item->available_at ?? $item->created_at, absolute: true))];
        });
    }

    public function confirmedAlias(ScrapeRunItem $item): ?array
    {
        $documentId = DocumentIdentifier::where('source_id', $item->run->source_id)->where('external_id', $item->source_external_id)->value('document_id');
        if ($documentId === null) {
            return null;
        }
        $collectOnce = ($item->run->checkpoint['collection_policy'] ?? null) === 'collect_once';
        $previous = ScrapeRunItem::where('scrape_run_id', $item->scrape_run_id)->where('document_id', $documentId)
            ->whereIn('status', $collectOnce ? ['imported', 'unchanged', 'review'] : ['imported', 'unchanged'])->where('id', '<>', $item->id)->orderBy('id')->first();
        $usableReview = $collectOnce && ($previous?->outcome['identity_verified'] ?? false) === true
            && ($previous?->outcome['persistence_complete'] ?? false) === true
            && ! ($previous?->outcome['technical_retry_pending'] ?? false)
            && collect($previous?->language_results ?? [])->every(fn ($result) => ($result['state'] ?? null) === 'metadata_only' || in_array($result['quality_status'] ?? null, ['verified', 'needs_review'], true));
        if (! $previous || (($previous->outcome['complete'] ?? false) !== true && ! $usableReview)) {
            return null;
        }
        if ($item->listing_status !== null && ($item->listing_status !== 'Y' || ! in_array($previous->outcome['source_status'] ?? null, ['active', 'pending'], true))) {
            return null;
        }

        return ['document_id' => $documentId, 'action' => 'skipped_alias', 'complete' => ($previous->outcome['complete'] ?? false) === true,
            'languages' => $previous->language_results, 'source_status' => $previous->outcome['source_status'] ?? null,
            'alias_of_item_id' => $previous->id, 'identity_verified' => true,
            'quality_status' => $previous->outcome['quality_status'] ?? 'verified',
            'reuse_reason' => $usableReview ? 'same_run_identity_verified_usable_collection' : 'same_run_verified_identity'];
    }

    public function finish(int $runId, string $sourceId, array $result, ?string $token = null): void
    {
        DB::transaction(function () use ($runId, $sourceId, $result, $token) {
            ScrapeRun::lockForUpdate()->findOrFail($runId);
            $item = ScrapeRunItem::where('scrape_run_id', $runId)->where('source_external_id', $sourceId)->lockForUpdate()->firstOrFail();
            if ($item->terminal() || ($token !== null && $item->dispatch_token !== $token)) {
                return;
            }
            if ($item->action === 'created') {
                $result['action'] = 'created';
            }
            $result['document_id'] ??= $item->document_id;
            $result['languages'] ??= $item->language_results ?? [];
            // Concurrent alias workers may both fetch, but only one canonical import counts.
            if (($result['complete'] ?? false) && ($result['action'] ?? null) !== 'skipped_alias' && ($alias = $this->confirmedAlias($item))) {
                $result = $alias + ['quality' => $result['quality'] ?? [], 'metrics' => $result['metrics'] ?? []];
            }
            if (isset($item->outcome['retry_control'])) {
                $result['retry_control'] = $item->outcome['retry_control'];
            }
            $status = match ($result['state'] ?? null) {
                'failed' => 'failed', 'review' => 'review',
                default => ($result['action'] ?? null) === 'skipped_alias' ? 'skipped_alias'
                    : (($result['complete'] ?? false) ? (($result['action'] ?? '') === 'unchanged' ? 'unchanged' : 'imported') : 'review'),
            };
            $item->moveTo($status, ['document_id' => $result['document_id'] ?? null, 'action' => $result['action'] ?? 'rejected',
                'language_results' => $result['languages'] ?? [], 'outcome' => $result,
                'review_reasons' => $status === 'review' ? ['languages' => $result['languages'] ?? [], 'quality' => $result['quality'] ?? [], 'validation' => $result['error'] ?? null] : null,
                'last_error' => $result['error'] ?? null, 'lease_expires_at' => null, 'available_at' => null]);
            $this->counters($runId);
        });
    }

    public function partial(int $id, string $token, array $result): void
    {
        $item = ScrapeRunItem::lockForUpdate()->findOrFail($id);
        if ($item->dispatch_token === $token && $item->status === 'processing') {
            $item->update(['document_id' => $result['document_id'], 'action' => $item->action === 'created' ? 'created' : $result['action'],
                'language_results' => $result['languages'], 'outcome' => $result + ['technical_retry_pending' => true, ...array_intersect_key($item->outcome ?? [], ['retry_control' => true])]]);
        }
    }

    public function retry(int $id, string $token, Throwable $exception, int $delay): void
    {
        DB::transaction(function () use ($id, $token, $exception, $delay) {
            $item = ScrapeRunItem::lockForUpdate()->findOrFail($id);
            if (! $item->terminal() && $item->dispatch_token === $token && $item->status === 'processing') {
                // DatabaseQueue release creates a NEW jobs.id; dispatch_token remains the receipt identity.
                $item->moveTo('retry_wait', ['available_at' => now()->addSeconds($delay), 'queue_job_id' => null, 'lease_expires_at' => null,
                    'last_error' => $exception::class.': '.mb_substr($exception->getMessage(), 0, 300)]);
            }
        });
    }

    public function counters(int $runId): void
    {
        $items = ScrapeRunItem::where('scrape_run_id', $runId);
        ScrapeRun::whereKey($runId)->update([
            'processed_count' => (clone $items)->whereIn('status', ScrapeRunItem::TERMINAL)->count(),
            'created_count' => (clone $items)->whereIn('status', ScrapeRunItem::TERMINAL)->where('action', 'created')->count(),
            'updated_count' => (clone $items)->whereIn('status', ScrapeRunItem::TERMINAL)->where('action', 'updated')->count(),
            'failed_count' => (clone $items)->where('status', 'failed')->count(),
        ]);
    }

    /** Requires an audited operator recovery transaction holding run/item locks. */
    public function reopenFailed(ScrapeRunItem $item, int $operatorEpoch): void
    {
        if ($item->status !== 'failed' || $item->run->items()->whereKey($item->id)->where('status', 'failed')->doesntExist()) {
            throw new \RuntimeException('Only the explicitly selected failed item can be reopened.');
        }
        $outcome = $item->outcome ?? [];
        $count = (int) ($outcome['retry_control']['recovery_count'] ?? 0);
        if ($count >= 3) {
            throw new \RuntimeException('Explicit source recovery limit exhausted.');
        }
        $outcome['retry_control'] = ['base_attempts' => $item->attempts,
            'limit' => max(1, min(3, (int) config('scraping.max_retries'))),
            'recovery_count' => $count + 1, 'operator_epoch' => $operatorEpoch];
        $item->update(['outcome' => $outcome]);
        if (! $this->enqueue($item)) {
            throw new \RuntimeException('Failed item could not receive its explicit bounded recovery receipt.');
        }
        $this->counters($item->scrape_run_id);
    }

    /** Called only by explicit resume; never retries old failed_jobs or terminal reviews. */
    public function recover(int $runId): int
    {
        $requeued = 0;
        ScrapeRunItem::where('scrape_run_id', $runId)->whereIn('status', ['discovered', 'queued', 'processing', 'retry_wait'])
            ->orderBy('id')->chunkById(100, function ($items) use (&$requeued) {
                foreach ($items as $item) {
                    $item = ScrapeRunItem::lockForUpdate()->findOrFail($item->id);
                    $job = DB::table('jobs')->where('queue', 'scraping')->where('payload', 'like', '%'.$item->dispatch_token.'%')->first();
                    if ($job && ($item->status !== 'processing' || $item->lease_expires_at?->isFuture()
                        || ($job->reserved_at !== null && $job->reserved_at + config('queue.connections.database.retry_after') > time()))) {
                        continue;
                    }
                    if ($item->status === 'processing' && $item->lease_expires_at?->isFuture()) {
                        continue;
                    }
                    if ($job) {
                        DB::table('jobs')->where('id', $job->id)->delete(); // only this proven-expired receipt
                    }
                    if ($this->enqueue($item)) {
                        $requeued++;
                    }
                }
            });

        if ($requeued > 0) {
            $this->counters($runId);
        }

        return $requeued;
    }
}
