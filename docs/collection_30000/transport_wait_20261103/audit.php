<?php

/** MAIN only, repeatable-read read-only evidence; no repairs or source HTTP. */
use App\Services\Scraping\CampaignIntegrity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT current_database() AS name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('MAIN database only.');
}
$before = json_decode(file_get_contents(__DIR__.'/baseline.json'), true, flags: JSON_THROW_ON_ERROR);
DB::beginTransaction();
DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
$campaign = DB::table('scrape_campaigns')->where('campaign_id', 'corpus-30000-20261030')->first();
$state = json_decode($campaign->state, true, flags: JSON_THROW_ON_ERROR);
$errors = app(CampaignIntegrity::class)->audit($before['baseline'], $campaign->campaign_id)['errors'];
foreach (['other_campaigns' => 'scrape_campaigns', 'checkpoints' => 'scrape_campaign_checkpoints', 'failed_jobs' => 'failed_jobs'] as $key => $table) {
    foreach ($before[$key] as $id => $hash) {
        $row = DB::table($table)->find($id);
        if (! $row || hash('sha256', json_encode($row)) !== $hash) {
            $errors[] = $key.'_changed:'.$id;
        }
    }
}
foreach (['started_at', 'configuration_snapshot', 'target_new_canonical', 'max_http_requests', 'max_source_selections', 'baseline_max_id', 'baseline_canonical_count'] as $key) {
    if ($before['campaign_before'][$key] !== $campaign->$key) {
        $errors[] = 'original_bounds_changed:'.$key;
    }
}
$receipts = [];
foreach ($before['jobs'] as $id => $hash) {
    $row = DB::table('jobs')->find($id);
    $receipts[$id] = ! $row ? 'consumed' : (hash('sha256', json_encode($row)) === $hash ? 'unchanged' : 'updated');
}
$counts = [];
foreach (['documents', 'document_identifiers', 'document_contents', 'scrape_runs', 'scrape_run_items', 'failed_jobs', 'jobs'] as $table) {
    $counts[$table] = DB::table($table)->count();
}
$counts['quality'] = DB::table('document_contents')->selectRaw('quality_status,count(*) AS n')->groupBy('quality_status')->pluck('n', 'quality_status')->all();
echo json_encode(['observed_at' => now()->toAtomString(), 'errors' => $errors, 'counts' => $counts,
    'campaign' => ['id' => $campaign->campaign_id, 'status' => $campaign->status, 'stop_reason' => $campaign->stop_reason,
        'new_canonical' => DB::table('documents')->where('id', '>', $campaign->baseline_max_id)->count(), 'http_used' => $campaign->used_http_requests,
        'http_cap' => $campaign->max_http_requests, 'selected_source_count' => $campaign->selected_source_count,
        'transport_wait' => $state['transport_wait'] ?? null, 'network_recovery_count' => $state['network_recovery_count'] ?? 0,
        'recovery_count' => $state['recovery_count'] ?? 0, 'http_status_counts' => $state['http_status_counts'] ?? [],
        'control' => array_intersect_key($state['control'] ?? [], array_flip(['phase', 'run_id', 'scope_index']))],
    'original_receipts' => $receipts, 'last_document' => DB::table('documents')->orderByDesc('id')->first(['id', 'external_id', 'created_at']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
DB::commit();
exit($errors === [] ? 0 : 1);
