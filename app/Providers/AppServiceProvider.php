<?php

namespace App\Providers;

use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzScraper;
use App\Services\Scraping\ArchiveCapture;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\PilotCampaign;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(LexHttpClient::class);
        $this->app->singleton(DurableCampaign::class);
        $this->app->singleton(ArchiveCapture::class);
        $this->app->bind(DocumentScraper::class, LexUzScraper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::viaRequest('corpus-report-token', function ($request) {
            $expected = config('corpus_reporting.token_sha256');
            $token = $request->bearerToken();
            if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected) || ! is_string($token) || strlen($token) < 32 || strlen($token) > 512
                || ! hash_equals($expected, hash('sha256', $token))) {
                return null;
            }

            return new GenericUser(['id' => 'corpus-report-reader', 'corpus_read' => true]);
        });
        Gate::define('corpus.read', fn ($user) => ($user->corpus_read ?? false) === true);
        if (config('queue.connections.database.retry_after') <= config('scraping.job_timeout')) {
            throw new \LogicException('Database queue retry_after must exceed SCRAPING_JOB_TIMEOUT.');
        }
        $reconcile = function ($event): void {
            $name = $event->job->payload()['displayName'] ?? '';
            if (preg_match('/^App\\\\Jobs\\\\(?:DiscoverDocumentsJob|ImportDocumentJob)#(\d+)$/D', $name, $match)) {
                $this->app->make(ScrapeRunManager::class)->reconcile((int) $match[1]);
            }
        };
        Queue::after($reconcile);
        Queue::failing($reconcile);
        Queue::looping(function ($event): ?bool {
            $durable = $this->app->make(DurableCampaign::class);
            if (in_array('scraping', explode(',', $event->queue), true) && $durable->exists()) {
                $state = $durable->state();
                if ($state['status'] !== 'running' || ! $state['owned_by_this_process']) {
                    $this->app->make('queue.worker')->shouldQuit = true;

                    return false;
                }
                $durable->assertOwner();
            }
            if (in_array('scraping', explode(',', $event->queue), true) && ($this->app->make(PilotCampaign::class)->state()['stopped'] ?? false)) {
                $this->app->make('queue.worker')->shouldQuit = true;

                return false;
            }

            return null;
        });
    }
}
