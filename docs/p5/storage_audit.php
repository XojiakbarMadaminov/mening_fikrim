<?php

/** Read-only size/status audit, no source requests. Run after pilot completion. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development database required.');
}
$baseline = json_decode(file_get_contents(__DIR__.'/database_baseline.json'), true, flags: JSON_THROW_ON_ERROR);
$maxContent = max(array_map('intval', array_keys($baseline['contents'])));
$maxDocument = max(array_column($baseline['documents'], 'id'));
$postgresVersion = DB::selectOne('select version() as version')->version;
$result = ['postgresql_version' => $postgresVersion, 'measurement_notes' => 'Markdown: UTF-8 octet_length. Metadata/candidate: pg_column_size, potentially compressed. Relations include indexes/TOAST; baseline physical bytes were not measured, so physical growth is unknown.', 'at' => now()->toAtomString(), 'tables' => [], 'baseline_physical_bytes' => null];
foreach (['documents', 'document_identifiers', 'document_contents', 'scrape_runs', 'scrape_run_items', 'failed_jobs', 'jobs', 'cache'] as $table) {
    $result['tables'][$table] = (int) DB::selectOne('select pg_total_relation_size(?::regclass) as bytes', [$table])->bytes;
}
$result['new_content_logical_bytes'] = (array) DB::selectOne('select coalesce(sum(octet_length(content)),0) as markdown,coalesce(sum(pg_column_size(extraction_metadata)),0) as extraction_metadata,coalesce(sum(pg_column_size(pending_candidate)),0) as pending_candidates from document_contents where id>?', [$maxContent]);
$result['legal_statuses_all'] = DB::table('documents')->selectRaw('status,count(*) as count')->groupBy('status')->pluck('count', 'status')->all();
$result['legal_statuses_new'] = DB::table('documents')->where('id', '>', $maxDocument)->selectRaw('status,count(*) as count')->groupBy('status')->pluck('count', 'status')->all();
$result['document_types_new'] = DB::table('documents')->where('id', '>', $maxDocument)->selectRaw('document_type,count(*) as count')->groupBy('document_type')->pluck('count', 'document_type')->all();
$result['new_pending_candidates'] = DB::table('document_contents')->where('id', '>', $maxContent)->whereNotNull('pending_candidate')->count();
file_put_contents(__DIR__.'/storage_statistics.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
