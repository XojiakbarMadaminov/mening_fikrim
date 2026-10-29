<?php

use App\Services\Scraping\PilotCampaign;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::selectOne('select current_database() as n')->n !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development DB required');
}
if (is_file(__DIR__.'/stage_a_preflight.json')) {
    throw new RuntimeException('Initial preflight is immutable; use final_checks instead.');
}
$state = app(PilotCampaign::class)->state();
$r = ['at' => now()->toAtomString(), 'campaign' => $state, 'campaign_hash' => hash('sha256', json_encode($state)), 'postgres' => DB::selectOne('select version() as version')->version, 'migrations' => DB::table('migrations')->orderBy('id')->pluck('migration')->all(), 'queue' => ['default' => config('queue.default'), 'retry_after' => config('queue.connections.database.retry_after'), 'jobs' => DB::table('jobs')->count()], 'safety' => ['live' => config('scraping.live_enabled'), 'scheduler' => config('scraping.scheduler_enabled'), 'workers' => config('scraping.workers'), 'delay_ms' => config('scraping.request_delay_ms'), 'request_budget' => config('scraping.request_budget'), 'page_budget' => config('scraping.page_budget')]];
file_put_contents(__DIR__.'/stage_a_preflight.json', json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
