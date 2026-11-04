<?php

/** Read-only MAIN evidence. Does not connect to the AI database or contact Lex.uz. */
use App\Services\Scraping\CampaignIntegrity;
use App\Services\Scraping\DurableCampaign;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT current_database() AS name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Only the MAIN development database is allowed.');
}
$original = json_decode(file_get_contents(__DIR__.'/baseline.json'), true, flags: JSON_THROW_ON_ERROR);
DB::beginTransaction();
DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
$id = 'corpus-30000-20261030';
$campaign = app(DurableCampaign::class)->state($id);
$integrity = app(CampaignIntegrity::class)->audit($original['baseline'], $id);
$historyErrors = [];
foreach ($original['historical_campaign_hashes'] as $key => $hash) {
    $row = DB::table('scrape_campaigns')->find($key);
    if (! $row || hash('sha256', json_encode($row)) !== $hash) {
        $historyErrors[] = 'historical_campaign_changed:'.$key;
    }
}
foreach ($original['checkpoint_hashes'] as $key => $hash) {
    $row = DB::table('scrape_campaign_checkpoints')->find($key);
    if (! $row || hash('sha256', json_encode($row)) !== $hash) {
        $historyErrors[] = 'original_checkpoint_changed:'.$key;
    }
}
$failures = [];
foreach (DB::table('failed_jobs')->orderBy('id')->get() as $row) {
    $firstLine = strtok($row->exception, "\n");
    $category = match (true) {
        str_contains($firstLine, 'Quality review') => 'legacy_parser_quality_review',
        str_contains($row->exception, 'tesseract') && str_contains($row->exception, 'Exit Code: 127') => 'ocr_dependency',
        str_contains($firstLine, 'LexRequestException') => 'http_network',
        str_contains($firstLine, 'QueryException') => 'database_queue',
        default => 'other',
    };
    $failures[] = ['id' => $row->id, 'category' => $category, 'sha256' => hash('sha256', json_encode($row)), 'exception_first_line' => $firstLine];
}
$failureHashes = array_column($failures, 'sha256', 'id');
foreach ($original['failures'] as $failure) {
    if (($failureHashes[$failure['id']] ?? null) !== $failure['sha256']) {
        $historyErrors[] = 'original_failed_job_changed:'.$failure['id'];
    }
}
foreach ($original['affected_items'] as $item) {
    if ($item['status'] !== 'review') {
        continue;
    }
    $row = DB::table('scrape_run_items')->find($item['id']);
    if (! $row || (array) $row != $item) {
        $historyErrors[] = 'completed_review_item_changed:'.$item['id'];
    }
}
$counts = [];
foreach (['documents', 'document_identifiers', 'document_contents', 'scrape_runs', 'scrape_run_items', 'failed_jobs', 'jobs'] as $table) {
    $counts[$table] = DB::table($table)->count();
}
$counts['quality'] = DB::table('document_contents')->selectRaw('quality_status,count(*) AS n')->groupBy('quality_status')->pluck('n', 'quality_status')->all();
$affected = DB::table('scrape_run_items')->whereIn('id', [16420, 16427, 16428])->orderBy('id')->get(['id', 'source_external_id', 'status', 'attempts', 'document_id', 'outcome', 'last_error']);
$campaign['context_count'] = count($campaign['contexts'] ?? []);
unset($campaign['contexts'], $campaign['selected_ids'], $campaign['control']['pool']);
echo json_encode(['observed_at' => now()->toAtomString(), 'database' => 'mening_fikrim_active', 'counts' => $counts,
    'campaign' => $campaign, 'integrity' => $integrity, 'history_errors' => $historyErrors,
    'failed_jobs' => $failures, 'affected_items' => $affected,
    'last_document' => DB::table('documents')->orderByDesc('id')->first(['id', 'external_id', 'created_at']),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
DB::commit();
exit($integrity['errors'] === [] && $historyErrors === [] ? 0 : 1);
