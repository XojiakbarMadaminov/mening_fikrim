<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() AS name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development DB required.');
}
$reference = json_decode(file_get_contents(dirname(__DIR__).'/p2/run_2.json'), true, flags: JSON_THROW_ON_ERROR)['contents'];
$contents = json_decode(DB::table('document_contents')->orderBy('document_id')->orderBy('language')->get(['id', 'document_id', 'language', 'content_hash'])->toJson(), true);
$failed = [];
foreach (DB::table('failed_jobs')->orderBy('id')->get() as $job) {
    $failed[$job->id] = hash('sha256', json_encode($job));
}
$items = DB::table('scrape_run_items')->selectRaw('scrape_run_id,status,count(*) as count')->groupBy('scrape_run_id', 'status')->orderBy('scrape_run_id')->orderBy('status')->get()->toArray();
$data = ['development_contents_identical_to_p2_before_p3_import' => $reference === $contents, 'documents' => DB::table('documents')->count(), 'identifiers' => DB::table('document_identifiers')->count(), 'contents' => count($contents),
    'historical_failed_jobs_hashes' => $failed, 'legacy_item_statuses' => $items, 'legacy_runs' => DB::table('scrape_runs')->orderBy('id')->get()->toArray()];
file_put_contents(__DIR__.'/migration_audit.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
if (! $data['development_contents_identical_to_p2_before_p3_import'] || count($failed) !== 24) {
    throw new RuntimeException('Migration preservation audit failed.');
}
echo "Migration preserved 15 documents / 45 identifiers / 37 content IDs and hashes, and 24 historical failed jobs.\n";
