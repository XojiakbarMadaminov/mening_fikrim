<?php

use App\Services\Scraping\PilotCampaign;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as n')->n !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed dev DB required');
}
$state = app(PilotCampaign::class)->state();
$preflight = json_decode(file_get_contents(__DIR__.'/stage_a_preflight.json'), true);
$r = ['at' => now()->toAtomString(), 'development_database' => DB::selectOne('select current_database() as n')->n,
    'configuration' => ['live' => config('scraping.live_enabled'), 'scheduler' => config('scraping.scheduler_enabled'), 'workers' => config('scraping.workers'), 'delay_ms' => config('scraping.request_delay_ms'), 'page_budget' => config('scraping.page_budget'), 'request_budget' => config('scraping.request_budget')],
    'campaign' => $state, 'new_canonical' => app(PilotCampaign::class)->newCount(), 'queue_jobs' => DB::table('jobs')->count(), 'failed_jobs' => DB::table('failed_jobs')->count(), 'migrations' => DB::table('migrations')->count(),
    'previous_campaign_unchanged' => hash('sha256', json_encode(Cache::get('lex:pilot:'.$preflight['campaign']['id']))) === $preflight['campaign_hash'],
    'temporary_pdf_files' => glob(config('scraping.temp_directory').'/pdf-*') ?: [],
    'environment_permissions' => ['.env' => decoct(fileperms(base_path('.env')) & 0777), '.env.testing' => decoct(fileperms(base_path('.env.testing')) & 0777)]];
$test = config('database.connections.pgsql');
$test['database'] = 'mening_fikrim_active_test';
config(['database.connections.p51_audit_test' => $test]);
$t = DB::connection('p51_audit_test');
if ($t->selectOne('select current_database() as n')->n !== 'mening_fikrim_active_test') {
    throw new RuntimeException('Fixed test DB required');
}
$r['test_database'] = ['migrations' => $t->table('migrations')->count(), 'sources' => $t->table('document_sources')->count(), 'documents' => $t->table('documents')->count(), 'jobs' => $t->table('jobs')->count(), 'failed_jobs' => $t->table('failed_jobs')->count()];
file_put_contents(__DIR__.'/final_checks.json', json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
