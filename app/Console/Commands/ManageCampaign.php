<?php

namespace App\Console\Commands;

use App\Services\Reporting\CampaignReportingService;
use App\Services\Scraping\AutonomousCampaign;
use App\Services\Scraping\AutonomousCampaignWorker;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\SourceArchive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class ManageCampaign extends Command
{
    protected $signature = 'documents:campaign {action=status : status, report, prepare, run, worker, start, pause, resume, work or stop} {--id=} {--requests=13000} {--sources=3300} {--target=1000} {--validation} {--reason=operator_stop} {--max-jobs=31} {--json} {--format=} {--snapshot=current} {--dry-run}';

    protected $description = 'Durable autonomous bounded corpus collection and read-only reports';

    public function handle(DurableCampaign $campaign, SourceArchive $archive, CampaignReportingService $reporting): int
    {
        $id = $this->option('id') ?? $campaign->id();
        $action = $this->argument('action');
        try {
            if ($this->option('format') !== null && ! in_array($this->option('format'), ['json', 'markdown'], true)) {
                throw new \InvalidArgumentException('Report format must be json or markdown.');
            }
            if (! in_array($this->option('snapshot'), ['current', 'final'], true)) {
                throw new \InvalidArgumentException('Snapshot must be current or final.');
            }
            if ($this->option('dry-run')) {
                $this->line(json_encode(['dry_run' => true, 'campaign' => $campaign->state($id), 'approved_profile' => config('corpus_campaigns.'.$id), 'source_requests' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }
            if (in_array($action, ['run', 'worker'], true)) {
                $this->liveGuard();

                return $this->operate((string) $id, $action, $reporting);
            }
            if ($action === 'prepare') {
                if (config('source_archive.enabled')) {
                    throw new \RuntimeException('Raw retention must remain disabled.');
                }
                app(AutonomousCampaign::class)->prepare((string) $id);
            } elseif ($action === 'start') {
                $campaign->create((string) $id, (int) $this->option('requests'), (int) $this->option('sources'), $this->option('validation') ? 0 : (int) $this->option('target'), ['campaign_type' => $this->option('validation') ? 'validation' : 'corpus_pilot', 'collect_once' => true]);
            } elseif (in_array($action, ['pause', 'stop'], true)) {
                if ($action === 'pause') {
                    $campaign->requestPause((string) $id);
                } else {
                    $campaign->requestStop((string) $id, (string) $this->option('reason'));
                }
                try {
                    $campaign->claim((string) $id);
                    $campaign->acknowledgeSignals();
                } catch (\RuntimeException $e) {
                    if (! str_contains($e->getMessage(), 'global PostgreSQL session lock')) {
                        throw $e;
                    }
                }
            } elseif (in_array($action, ['resume', 'work'], true)) {
                $campaign->claim((string) $id);
                if ($action === 'resume') {
                    $campaign->resume();
                } else {
                    if (! config('scraping.live_enabled') || (int) $this->option('max-jobs') < 1 || (int) $this->option('max-jobs') > 31) {
                        throw new \RuntimeException('Explicit live flag and --max-jobs=1..31 required.');
                    }
                    $campaign->assertOpen();
                    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--tries' => 3, '--timeout' => 900, '--max-jobs' => (int) $this->option('max-jobs'), '--stop-when-empty' => true]);
                    $this->line(Artisan::output());
                }
            } elseif (! in_array($action, ['status', 'report'], true)) {
                throw new \InvalidArgumentException('Unknown campaign action.');
            }
            $current = $campaign->state($id);
            if ($action === 'stop' && ($current['configuration_snapshot']['autonomous'] ?? false) && in_array($current['status'], ['stopped', 'completed', 'failed'], true)) {
                $campaign->recordFinalReport($reporting->campaign($id));
            }
            if ($this->option('json') || $this->option('format') !== null || $this->option('snapshot') === 'final') {
                $report = $id === null ? $reporting->campaigns() : ($this->option('snapshot') === 'final' ? $reporting->finalReport($id) : $reporting->campaign($id));
                $this->line($this->option('format') === 'markdown' ? $reporting->markdown($report) : json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return self::SUCCESS;
            }
            // Preserve the pre-P6.1 default JSON contract.
            $state = $campaign->state($id);
            if ($state) {
                $state['request_context_count'] = count($state['contexts'] ?? []);
                unset($state['selected_ids'], $state['contexts'], $state['control']);
            }
            $result = ['campaign' => $state, 'pending_scraping_jobs' => DB::table('jobs')->where('queue', 'scraping')->count()];
            if ($action === 'report') {
                $result['archive'] = $archive->audit();
            }
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $campaign->release();
        }
    }

    private function liveGuard(): void
    {
        if (! config('scraping.live_enabled') || config('scraping.scheduler_enabled') || config('scraping.workers') !== 1 || config('scraping.request_delay_ms') < 2000
            || config('scraping.request_budget') > 400 || config('queue.default') !== 'database' || config('cache.default') !== 'database' || config('source_archive.enabled')) {
            throw new \RuntimeException('Explicit live flag, one worker, >=2000ms, context<=400, database queue/cache, disabled scheduler/raw retention required.');
        }
    }

    private function operate(string $id, string $action, CampaignReportingService $reporting): int
    {
        $actor = $action === 'run' ? app(AutonomousCampaign::class) : app(AutonomousCampaignWorker::class);
        $begun = false;
        $this->trap([SIGINT, SIGTERM], fn () => $actor->requestShutdown());
        try {
            if ($action === 'run') {
                $actor->begin($id);
            } else {
                $actor->begin();
            }
            $begun = true;
            $this->info('Campaign '.$id.' '.$action.' started; PostgreSQL is authoritative.');
            while ($action === 'run' ? $actor->tick() : $actor->tick($id)) {
                usleep(500000);
            }
            $this->line($reporting->markdown($reporting->campaign($id)));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            if ($begun && $action === 'run' && in_array(app(DurableCampaign::class)->state($id)['status'] ?? null, ['stopped', 'completed', 'failed'], true)) {
                $actor->persistFinal();
            }
            throw $e;
        } finally {
            $actor->close();
        }
    }
}
