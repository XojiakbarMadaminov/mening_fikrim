<?php

namespace App\Jobs;

use App\Models\ScrapeRun;
use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexRequestException;
use App\Scraping\LexUz\SourceReviewException;
use App\Services\Scraping\PilotCampaign;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class DiscoverDocumentsJob implements ShouldQueue
{
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(public int $runId)
    {
        $this->onConnection('database')->onQueue('scraping');
        $this->tries = config('scraping.max_retries');
        $this->timeout = config('scraping.job_timeout');
    }

    public function displayName(): string
    {
        return self::class.'#'.$this->runId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('lex:single-worker'))->shared()->releaseAfter(5)->expireAfter($this->timeout + 30)];
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(DocumentScraper $scraper, ScrapeRunManager $manager): void
    {
        $run = ScrapeRun::findOrFail($this->runId);
        if (! app(PilotCampaign::class)->allowsRun($run)) {
            $this->release(3600);
            app('queue.worker')->shouldQuit = true;

            return;
        }
        $cp = $run->checkpoint;
        if ($cp['discovery_finished']) {
            return;
        }
        if ($run->items()->count() >= $cp['limit']) {
            $manager->discoveryResult($run->id, ['complete' => false, 'reason' => 'limit_already_committed']);

            return;
        }
        app(LexHttpClient::class)->context('run-'.$run->id);
        if ($cp['sample_ids'] !== [] || $run->mode === 'recheck') {
            $manager->explicitSelection($run->id);

            return;
        }
        try {
            $result = $scraper->discover($cp['segments'][$cp['segment_index']], fn ($page) => $manager->selectPage($run->id, $page), config('scraping.page_budget'));
            $manager->discoveryResult($run->id, $result);
        } catch (LexRequestException $e) {
            if ($e->status === 403 || ($e->status > 0 && $e->status < 500 && $e->status !== 429)) {
                $this->fail($e);
            } elseif ($e->retryAfter !== null && $this->attempts() < $this->tries) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (\RuntimeException $e) {
            if ($e instanceof SourceReviewException || str_starts_with($e->getMessage(), 'coverage_gap:')) {
                $manager->discoveryFailure($run->id, $e->getMessage());
            } else {
                throw $e; // Unexpected parser/process/DB errors retain Laravel retries.
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(ScrapeRunManager::class)->discoveryFailure($this->runId, $exception === null ? 'Unknown discovery failure.' : $exception::class.': '.mb_substr($exception->getMessage(), 0, 250));
    }
}
