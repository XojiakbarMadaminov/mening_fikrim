<?php

namespace App\Jobs;

use App\Models\ScrapeRun;
use App\Models\ScrapeRunItem;
use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexRequestException;
use App\Scraping\LexUz\SourceReviewException;
use App\Services\Documents\DocumentImporter;
use App\Services\Documents\DocumentValidator;
use App\Services\Scraping\PilotCampaign;
use App\Services\Scraping\RunItemTracker;
use App\Services\Scraping\ScrapeRunManager;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ImportDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public int $sourceId, public array|string $candidate, public ?string $checkedAt = null, public ?int $runId = null, public ?string $listingStatus = null, public ?int $itemId = null, public ?string $dispatchToken = null)
    {
        $this->onConnection('database')->onQueue('scraping')->afterCommit();
        $this->tries = config('scraping.max_retries');
        $this->timeout = config('scraping.job_timeout');
    }

    public function displayName(): string
    {
        return self::class.($this->runId === null ? '' : '#'.$this->runId);
    }

    public function middleware(): array
    {
        // P3 allows offline parallel parsing. Short DB claims and the shared HTTP pacer protect work.
        return [];
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(DocumentImporter $importer): void
    {
        if ($this->runId === null) {
            $this->prepared($importer);

            return;
        }
        if (! app(PilotCampaign::class)->allowsRun(ScrapeRun::findOrFail($this->runId))) {
            $this->release(3600);
            app('queue.worker')->shouldQuit = true;

            return;
        }
        $tracker = app(RunItemTracker::class);
        $item = ScrapeRunItem::where('scrape_run_id', $this->runId)->where('source_external_id', (string) $this->candidate)->firstOrFail();
        $this->itemId ??= $item->id;
        $this->dispatchToken ??= $item->dispatch_token;
        $claim = $tracker->claim($this->itemId, $this->dispatchToken, isset($this->job) ? (string) $this->job->getJobId() : null);
        if (! $claim['item']) {
            if (isset($claim['wait'])) {
                $this->release($claim['wait']);
            }

            return;
        }
        $item = $claim['item'];
        $manager = app(ScrapeRunManager::class);
        app(LexHttpClient::class)->context('run-'.$this->runId);
        $candidate = null;
        $started = hrtime(true);
        try {
            if ($alias = $tracker->confirmedAlias($item)) {
                $manager->outcome($this->runId, (string) $this->candidate, $alias, $this->dispatchToken);

                return;
            }
            // No source/import transaction is open during HTTP, HTML parsing, PDF or OCR.
            $candidate = app(DocumentScraper::class)->fetch((string) $this->candidate, $this->listingStatus);
            $fetchParseSeconds = (hrtime(true) - $started) / 1e9;
            $checkedAt = new DateTimeImmutable;
            $sourceStatus = app(DocumentValidator::class)->status($candidate['status_evidence'], $checkedAt)['status']->value;
            $result = DB::transaction(function () use ($importer, $candidate, $checkedAt, $manager, $sourceStatus, $started, $claim, $fetchParseSeconds) {
                // Consistent run -> item -> source lock order; no network/parse work here.
                ScrapeRun::lockForUpdate()->findOrFail($this->runId);
                $receipt = ScrapeRunItem::lockForUpdate()->findOrFail($this->itemId);
                if ($receipt->dispatch_token !== $this->dispatchToken || $receipt->status !== 'processing') {
                    return null;
                }
                $result = $importer->import($this->sourceId, $candidate, $checkedAt);
                if (! isset($candidate['technical_exception'])) {
                    $ids = array_map(fn ($node) => $node['external_id'], $candidate['identity']);
                    sort($ids, SORT_STRING);
                    $manager->outcome($this->runId, (string) $this->candidate, [
                        ...$result, 'state' => $result['complete'] ? 'valid' : 'review',
                        'format' => $candidate['diagnostics']['primary_format'], 'source_status' => $sourceStatus,
                        'identity_key' => hash('sha256', json_encode($ids)), 'identity_verified' => true,
                        'quality' => $this->qualitySummary($candidate['diagnostics']['quality']),
                        'metrics' => ['queue_wait_seconds' => $claim['queue_wait_seconds'], 'fetch_parse_seconds' => round($fetchParseSeconds, 6), 'fetch_parse_import_seconds' => round((hrtime(true) - $started) / 1e9, 6)],
                        'error' => $result['complete'] ? null : 'Language/structure quality requires review.',
                    ], $this->dispatchToken);
                } else {
                    app(RunItemTracker::class)->partial($this->itemId, $this->dispatchToken, $result);
                }

                return $result;
            });
            if ($result !== null && isset($candidate['technical_exception'])) {
                // Valid independent languages have committed; a technical failure still retries/fails.
                throw $candidate['technical_exception'];
            }
        } catch (ValidationException $e) {
            $identityIds = array_map(fn ($node) => $node['external_id'], $candidate['identity'] ?? []);
            sort($identityIds, SORT_STRING);
            $manager->outcome($this->runId, (string) $this->candidate, ['state' => 'review', 'action' => 'rejected', 'document_id' => null, 'complete' => false,
                'source_status' => $sourceStatus ?? null, 'identity_verified' => $identityIds !== [] && ! isset($e->errors()['identity']),
                'identity_key' => $identityIds === [] ? null : hash('sha256', json_encode($identityIds)),
                'format' => $candidate['diagnostics']['primary_format'] ?? null,
                'quality' => isset($candidate['diagnostics']['quality']) ? $this->qualitySummary($candidate['diagnostics']['quality']) : [],
                'error' => mb_substr(json_encode($e->errors(), JSON_UNESCAPED_UNICODE), 0, 1500)], $this->dispatchToken);
        } catch (SourceReviewException $e) {
            $manager->outcome($this->runId, (string) $this->candidate, ['state' => 'review', 'action' => 'rejected', 'document_id' => null, 'complete' => false,
                'error' => $e->getMessage()], $this->dispatchToken);
        } catch (Throwable $e) {
            $delay = $e instanceof LexRequestException && $e->retryAfter !== null ? $e->retryAfter : $this->backoff()[min($item->attempts - 1, 2)];
            if ($e instanceof LexRequestException && ($e->status === 403 || ($e->status > 0 && $e->status < 500 && $e->status !== 429))) {
                $this->fail($e);

                return;
            }
            $tracker->retry($this->itemId, $this->dispatchToken, $e, $delay);
            if ($e instanceof LexRequestException && $e->retryAfter !== null && $this->attempts() < $this->tries) {
                $this->release($delay);
            } else {
                throw $e;
            }
        }
    }

    private function prepared(DocumentImporter $importer): void
    {
        try {
            $result = $importer->import($this->sourceId, $this->candidate, new DateTimeImmutable($this->checkedAt));
        } catch (ValidationException $e) {
            $result = ['document_id' => null, 'action' => 'rejected', 'complete' => false, 'languages' => [], 'error' => json_encode($e->errors())];
        }
        if ($result['complete']) {
            return; // Existing successful P1 prepared-candidate interface remains unchanged.
        }
        DB::transaction(function () use ($result) {
            $run = ScrapeRun::create(['source_id' => $this->sourceId, 'mode' => 'recheck', 'started_at' => now(),
                'checkpoint' => ['version' => 3, 'limit' => 1, 'scope' => 'prepared_candidate', 'discovery_finished' => true, 'coverage_verified' => false]]);
            $item = $run->items()->create(['source_external_id' => (string) ($this->candidate['entry_id'] ?? 'prepared'), 'status' => 'processing', 'attempts' => 1, 'started_at' => now()]);
            app(RunItemTracker::class)->finish($run->id, $item->source_external_id, $result + ['state' => 'review']);
            app(ScrapeRunManager::class)->reconcile($run->id);
        });
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->runId !== null) {
            app(ScrapeRunManager::class)->outcome($this->runId, (string) $this->candidate, ['state' => 'failed', 'action' => 'rejected', 'document_id' => null, 'complete' => false,
                'error' => $exception === null ? 'Unknown technical failure.' : $exception::class.': '.mb_substr($exception->getMessage(), 0, 300)], $this->dispatchToken);
        }
    }

    private function qualitySummary(array $languages): array
    {
        $summary = [];
        foreach ($languages as $language => $quality) {
            $summary[$language] = array_intersect_key($quality, array_flip(['format', 'issues', 'chars', 'headings', 'token_fidelity', 'pages_expected', 'pages_extracted', 'ocr_pages', 'page_stats', 'critical_tokens', 'validation_levels', 'provenance', 'tables', 'images', 'timings', 'layout_diagnostics', 'error', 'error_class']));
        }

        return $summary;
    }
}
