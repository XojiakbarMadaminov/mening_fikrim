<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development database required.');
}
$old = json_decode(file_get_contents(dirname(__DIR__).'/p2/run_2.json'), true)['contents'];
$old = array_column($old, 'content_hash', 'id');
$rows = [];
foreach (DB::table('document_contents')->orderBy('id')->get() as $row) {
    $hash = hash('sha256', $row->content);
    if ($hash !== $row->content_hash) {
        throw new RuntimeException('Stored content hash mismatch.');
    }
    $state = isset($old[$row->id]) ? ($old[$row->id] === $hash ? 'unchanged' : 'changed') : 'new';
    $reconstructed = false;
    if ($state === 'changed') {
        $marker = match ($row->language) {
            'uz-Latn' => '## 1-bob.','uz-Cyrl' => '## 1-боб.','ru' => '## Глава 1.',default => null
        };
        $position = $marker === null ? false : strpos($row->content, $marker);
        if ($position !== false) {
            $restored = substr($row->content, 0, $position)."## \n\n".substr($row->content, $position);
            $reconstructed = hash('sha256', $restored) === $old[$row->id];
        }
        if ($reconstructed) {
            $state = 'only_empty_heading_removed';
        }
    }
    preg_match_all('/^#{1,3}\s*$/mu', $row->content, $empty);
    $rows[] = ['id' => $row->id, 'document_id' => $row->document_id, 'language' => $row->language, 'state' => $state, 'old_sha256' => $old[$row->id] ?? null, 'sha256' => $hash, 'characters' => mb_strlen($row->content), 'empty_headings' => count($empty[0]), 'old_hash_reconstructed_by_reinserting_empty_heading' => $reconstructed];
}
$oldFailed = json_decode(file_get_contents(__DIR__.'/migration_audit.json'), true)['historical_failed_jobs_hashes'];
$preserved = true;
foreach ($oldFailed as $id => $hash) {
    $job = DB::table('failed_jobs')->where('id', $id)->first();
    $preserved = $preserved && $job !== null && hash('sha256', json_encode($job)) === $hash;
}
$summary = ['contents' => $rows, 'old_failed_jobs_hashes_preserved' => $preserved, 'failed_jobs_count' => DB::table('failed_jobs')->count(),
    'item_status_counts' => DB::table('scrape_run_items')->selectRaw('status,count(*) as count')->groupBy('status')->orderBy('status')->get()->toArray(),
    'checkpoint_max_bytes' => DB::selectOne('select max(pg_column_size(checkpoint)) as bytes from scrape_runs')->bytes,
    'item_max_outcome_bytes' => DB::selectOne('select max(pg_column_size(outcome)) as bytes from scrape_run_items')->bytes,
    'documents' => DB::table('documents')->orderBy('id')->get(['id', 'external_id', 'status', 'effective_at', 'last_checked_at'])->toArray()];
file_put_contents(__DIR__.'/content_audit.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo json_encode(['content_states' => array_count_values(array_column($rows, 'state')), 'empty_headings' => array_sum(array_column($rows,'empty_headings')), 'old_failed_jobs_preserved' => $preserved], JSON_PRETTY_PRINT)."\n";
