<?php

use App\Models\Document;
use App\Models\DocumentContent;
use App\Services\Scraping\PilotCampaign;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/** Read-only database evidence export. No network or source reprocessing. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::selectOne('select current_database() as n')->n !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development DB required');
}
function writeEvidence(string $name, array $value): void
{
    file_put_contents(__DIR__.'/'.$name.'.json', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
}
$pilot = app(PilotCampaign::class);
$state = $pilot->state();
$docs = [];
$availability = [];
$counts = ['available' => 0, 'not_available' => 0, 'unknown' => 0, 'available_but_failed' => 0, 'unassessed_documents' => 0];
foreach (Document::with(['contents', 'identifiers'])->orderBy('id')->cursor() as $d) {
    $observations = $d->metadata['language_availability'] ?? [];
    $contents = [];
    foreach ($d->contents as $c) {
        $contents[$c->language] = ['id' => $c->id, 'hash' => $c->content_hash, 'characters' => mb_strlen($c->content), 'quality_status' => $c->quality_status, 'quality_issues' => $c->quality_issues, 'format' => $c->extraction_metadata['quality']['format'] ?? null, 'pages' => $c->extraction_metadata['quality']['pages_expected'] ?? null, 'source_url' => $c->extraction_metadata['source_url'] ?? null, 'pending_candidate' => $c->pending_candidate !== null];
    }
    if (! $observations) {
        $counts['unassessed_documents']++;
    }
    foreach ($observations as $language => $e) {
        $counts[$e['status']]++;
        $outcome = $d->metadata['validation']['languages'][$language] ?? [];
        $failed = $e['status'] === 'available' && (! isset($contents[$language]) || ($outcome['quality_status'] ?? null) === 'extraction_failed');
        if ($failed) {
            $counts['available_but_failed']++;
        }
        $availability[] = ['document_id' => $d->id, 'external_id' => $d->external_id, 'language' => $language, 'observation' => $e, 'extraction' => $e['status'] === 'not_available' ? 'not_attempted' : ($failed ? 'failed' : (isset($contents[$language]) ? 'succeeded' : 'not_attempted')), 'quality' => $contents[$language]['quality_status'] ?? null];
    }
    $docs[] = ['id' => $d->id, 'external_id' => $d->external_id, 'source_url' => $d->source_url, 'legal_status' => $d->status->value, 'quality_status' => $d->quality_status, 'language_coverage_status' => $d->language_coverage_status, 'availability' => $observations, 'contents' => $contents, 'identifiers' => $d->identifiers->pluck('external_id')->all()];
}
writeEvidence('documents', ['campaign' => $state, 'documents' => $docs]);
writeEvidence('language_availability', ['counts' => $counts, 'scope' => 'Only persisted P5.1 evidence; legacy documents without an observation map are counted separately as unassessed, never inferred absent.', 'representations' => $availability]);
writeEvidence('quality_statistics', ['all_contents' => DocumentContent::count(), 'quality' => DocumentContent::selectRaw('quality_status,count(*) as count')->groupBy('quality_status')->pluck('count', 'quality_status')->all(), 'document_quality' => array_count_values(array_column($docs, 'quality_status')), 'coverage' => array_count_values(array_column($docs, 'language_coverage_status'))]);
writeEvidence('storage_statistics', ['at' => now()->toAtomString(), 'database_bytes' => (int) DB::selectOne('select pg_database_size(current_database()) as n')->n, 'table_bytes' => DB::select("select relname,pg_total_relation_size(relid) as bytes from pg_catalog.pg_statio_user_tables where relname in ('documents','document_contents','document_identifiers','scrape_runs','scrape_run_items') order by relname")]);
echo json_encode(['new' => $pilot->newCount(), 'documents' => count($docs), 'contents' => DocumentContent::count(), 'availability' => $counts], JSON_PRETTY_PRINT)."\n";
