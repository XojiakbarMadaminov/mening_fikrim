<?php

/** Read-only development snapshots. No credentials or full legal text are serialized. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development database required.');
}
$phase = $argv[1] ?? 'before';
if (! in_array($phase, ['before', 'migrated', 'imported', 'repeat', 'final'], true)) {
    throw new RuntimeException('Unknown audit phase.');
}
$result = ['at' => now()->toAtomString(), 'rows' => [], 'record_hashes' => [], 'contents' => []];
foreach (['document_sources', 'documents', 'document_identifiers', 'document_contents', 'scrape_runs', 'scrape_run_items', 'failed_jobs', 'jobs', 'migrations'] as $table) {
    $rows = DB::table($table)->orderBy('id')->get();
    $result['rows'][$table] = $rows->count();
    foreach ($rows as $record) {
        $data = (array) $record;
        foreach (['quality_status', 'quality_issues', 'quality_checked_at', 'extraction_metadata', 'pending_candidate'] as $field) {
            if ($table === 'document_contents') {
                unset($data[$field]);
            }
        }
        ksort($data);
        $result['record_hashes'][$table][$record->id] = hash('sha256', json_encode($data));
        if ($table === 'document_contents') {
            $result['contents'][$record->id] = ['document_id' => $record->document_id, 'language' => $record->language,
                'hash' => $record->content_hash, 'hash_matches' => hash('sha256', $record->content) === $record->content_hash,
                'last_fetched_at' => $record->last_fetched_at,
                'quality_checked_at' => $record->quality_checked_at ?? null,
                'quality_status' => $record->quality_status ?? null, 'quality_issues' => isset($record->quality_issues) ? json_decode($record->quality_issues, true) : null,
                'pending_candidate_hash' => isset($record->pending_candidate) ? (json_decode($record->pending_candidate, true)['content_hash'] ?? null) : null];
        }
    }
}
$result['quality_counts'] = array_count_values(array_filter(array_column($result['contents'], 'quality_status')));
$result['configuration'] = ['live_enabled' => config('scraping.live_enabled'), 'scheduler_enabled' => config('scraping.scheduler_enabled'), 'workers' => config('scraping.workers'), 'request_delay_ms' => config('scraping.request_delay_ms')];
file_put_contents(__DIR__.'/database_'.$phase.'.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo json_encode(['phase' => $phase, 'rows' => $result['rows'], 'quality_counts' => $result['quality_counts'], 'configuration' => $result['configuration']], JSON_PRETTY_PRINT)."\n";
