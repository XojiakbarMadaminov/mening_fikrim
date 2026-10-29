<?php

namespace App\Providers;

use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzScraper;
use App\Services\Scraping\PilotCampaign;
use App\Services\Scraping\ScrapeRunManager;
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
        $this->app->bind(DocumentScraper::class, LexUzScraper::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
            if (in_array('scraping', explode(',', $event->queue), true) && ($this->app->make(PilotCampaign::class)->state()['stopped'] ?? false)) {
                $this->app->make('queue.worker')->shouldQuit = true;

                return false;
            }

            return null;
        });
    }
}
