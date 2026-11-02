<?php

use App\Services\Reporting\CampaignReportingService;
use App\Services\Scraping\CampaignIntegrity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT current_database() AS name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development database required.');
}
$baselinePath = __DIR__.'/baseline.json';
$mode = $argv[1] ?? 'check';
if ($mode === 'baseline') {
    if (is_file($baselinePath)) {
        throw new RuntimeException('Never replace the baseline.');
    }
    $value = ['observed_at' => now()->toAtomString(), 'corpus' => app(CampaignReportingService::class)->corpus(),
        'preservation' => app(CampaignIntegrity::class)->baseline(),
        'campaigns' => DB::table('scrape_campaigns')->orderBy('id')->get()->mapWithKeys(fn ($row) => [$row->id => hash('sha256', json_encode($row))])->all()];
    file_put_contents($baselinePath, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    echo json_encode($value['corpus'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
} else {
    $baseline = json_decode(file_get_contents($baselinePath), true, flags: JSON_THROW_ON_ERROR);
    $audit = app(CampaignIntegrity::class)->audit($baseline['preservation'], 'corpus-30000-20261030');
    foreach ($baseline['campaigns'] as $id => $hash) {
        $row = DB::table('scrape_campaigns')->where('id', $id)->first();
        if ($row?->campaign_id === 'corpus-30000-20261030') {
            $before = json_decode(file_get_contents(__DIR__.'/before.json'), true)['campaign_before'];
            foreach (['started_at', 'baseline_max_id', 'baseline_canonical_count', 'configuration_snapshot', 'max_http_requests', 'max_source_selections', 'target_new_canonical'] as $field) {
                if ($before[$field] !== $row->{$field}) {
                    $audit['errors'][] = 'original_campaign_bound_changed:'.$field;
                }
            }
            foreach (['used_http_requests', 'selected_source_count'] as $field) {
                if ($row->{$field} < $before[$field]) {
                    $audit['errors'][] = 'campaign_counter_decreased:'.$field;
                }
            }

            continue; // The explicit recovery journal preserves this campaign's prior terminal record.
        }
        if ($row === null || hash('sha256', json_encode($row)) !== $hash) {
            $audit['errors'][] = 'historical_campaign_changed:'.$id;
        }
    }
    $audit['corpus'] = app(CampaignReportingService::class)->corpus();
    file_put_contents(__DIR__.'/integrity_latest.json', json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
    echo json_encode($audit, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
    exit($audit['errors'] === [] ? 0 : 1);
}
