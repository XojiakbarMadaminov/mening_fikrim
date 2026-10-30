<?php

namespace App\Services\Scraping;

use App\Models\ScrapeRun;
use App\Services\Reporting\CampaignReportingService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AutonomousCampaignWorker
{
    private CampaignSessionMutex $mutex;

    private bool $shutdown = false;

    public function __construct(private DurableCampaign $campaign)
    {
        $this->mutex = new CampaignSessionMutex(3);
    }

    public function begin(): void
    {
        $this->mutex->acquire();
    }

    public function requestShutdown(): void
    {
        $this->shutdown = true;
    }

    public function tick(string $id): bool
    {
        $this->mutex->acquire();
        if ($this->shutdown) {
            return false;
        }
        $state = $this->campaign->state($id) ?? throw new RuntimeException('Prepare the campaign first.');
        if ($this->campaign->id() !== $id || ! ($state['configuration_snapshot']['autonomous'] ?? false)) {
            throw new RuntimeException('Worker requires the current autonomous campaign.');
        }
        if (in_array($state['status'], ['completed', 'stopped', 'failed'], true)) {
            return false;
        }
        if ($state['status'] === 'paused' || ($state['status'] === 'running' && (($state['control']['phase'] ?? null) !== 'wait' || ! DB::table('jobs')->where('queue', 'scraping')->exists()))) {
            return true;
        }
        try {
            $this->campaign->claim($id);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'global PostgreSQL session lock')) {
                return true;
            }
            throw $e;
        }
        try {
            if ($this->campaign->acknowledgeSignals()) {
                return true;
            }
            $this->campaign->assertOpen();
            foreach (DB::table('jobs')->where('queue', 'scraping')->select('payload')->get() as $job) {
                $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
                $name = $payload['displayName'] ?? '';
                if (! preg_match('/^App\\\\Jobs\\\\(?:ImportDocumentJob|DiscoverDocumentsJob)#(\d+)$/D', $name, $match)
                    || ! ScrapeRun::whereKey($match[1])->where('checkpoint->campaign_id', $id)->exists()) {
                    $this->campaign->stop('foreign_queue_receipt');

                    return false;
                }
            }
            if ($this->campaign->queueDelaySeconds() > 0) {
                return true; // Worker restarts do not consume a still-live queue reservation.
            }
            app('queue.worker')->shouldQuit = false;
            $handlers = [];
            if (function_exists('pcntl_signal_get_handler')) {
                foreach ([SIGINT, SIGTERM, SIGQUIT, SIGUSR2, SIGCONT] as $signal) {
                    $handlers[$signal] = pcntl_signal_get_handler($signal);
                }
            }
            try {
                Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--tries' => 3, '--timeout' => 900,
                    '--max-jobs' => 1, '--max-time' => 905, '--memory' => 512, '--sleep' => 1, '--stop-when-empty' => true]);
            } finally {
                foreach ($handlers as $signal => $handler) {
                    pcntl_signal($signal, $handler);
                }
            }
            if (app('queue.worker')->shouldQuit) {
                $this->shutdown = true;
            }
            $this->campaign->acknowledgeSignals();

            return ! $this->shutdown;
        } catch (PilotStoppedException $e) {
            $this->campaign->acknowledgeSignals();

            return true;
        } finally {
            try {
                $current = $this->campaign->state($id);
                if (in_array($current['status'], ['stopped', 'completed', 'failed'], true)) {
                    $this->campaign->recordFinalReport(app(CampaignReportingService::class)->campaign($id));
                }
            } finally {
                $this->campaign->release();
            }
        }
    }

    public function close(): void
    {
        $this->campaign->release();
        $this->mutex->release();
    }
}
