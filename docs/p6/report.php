<?php

use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\ScrapeCampaign;
use App\Models\ScrapeRun;
use App\Services\Scraping\DurableCampaign;
use App\Services\Scraping\SourceArchive;
use Illuminate\Support\Facades\DB;

require __DIR__.'/runtime.php';
$audit = p6Audit('final_integrity', false);
$baseline = p6Read('database_baseline');
$campaigns = ScrapeCampaign::orderBy('id')->get();
$collection = $campaigns->firstWhere('campaign_id', 'p6-corpus-1000-20261029');
$newCount = $collection ? app(DurableCampaign::class)->newCount(['id' => $collection->campaign_id, 'baseline_max_id' => $collection->baseline_max_id]) : 0;
$runIds = $collection ? p6RunIds($collection->campaign_id) : [];
$runs = ScrapeRun::whereIn('id', $runIds)->get();
$items = $runs->flatMap(fn ($run) => $run->items()->orderBy('id')->get());
$newContents = DocumentContent::whereNotIn('id', array_keys($baseline['contents']))->get();
$requests = [];
$cacheHits = 0;
$cacheAvoidedBytes = 0;
if (is_file(__DIR__.'/requests.jsonl')) {
    foreach (new SplFileObject(__DIR__.'/requests.jsonl') as $line) {
        $record = json_decode($line, true);
        if (($record['message'] ?? null) === 'lex.request') {
            $requests[] = $record['context'] + ['logged_at' => $record['datetime']];
        } elseif (($record['message'] ?? null) === 'lex.cache_hit') {
            $cacheHits++;
        }
    }
}
$purposes = [];
$statuses = [];
$contexts = [];
$starts = [];
$bytesByHash = [];
$seenGet = [];
$duplicateGet = 0;
$retryCount = 0;
$networkSeconds = 0.0;
$collectionHttpSeconds = 0.0;
foreach ($requests as $request) {
    $purpose = $request['purpose'] ?? 'unknown';
    $purposes[$purpose] = ($purposes[$purpose] ?? 0) + 1;
    $status = (string) ($request['status'] ?? 0);
    $statuses[$status] = ($statuses[$status] ?? 0) + 1;
    $contexts[$request['campaign_id']] = ($contexts[$request['campaign_id']] ?? 0) + 1;
    $starts[] = $request['start_monotonic'];
    $retryCount += (int) (($request['attempt'] ?? 1) > 1);
    $networkSeconds += $request['elapsed_seconds'];
    if ($request['campaign_id'] === 'p6-corpus-1000-20261029') {
        $collectionHttpSeconds += $request['elapsed_seconds'];
    }
    if (isset($request['sha256'])) {
        $bytesByHash[$request['sha256']] = $request['bytes'];
    }
    if ($request['method'] === 'GET') {
        $key = $request['url'].'|'.($request['sha256'] ?? 'unknown');
        $duplicateGet += isset($seenGet[$key]) ? 1 : 0;
        $seenGet[$key] = true;
    }
}
sort($starts);
$intervals = [];
for ($i = 1; $i < count($starts); $i++) {
    $intervals[] = $starts[$i] - $starts[$i - 1];
}
$reserved = $campaigns->sum('used_http_requests');
$http = ['reserved_requests' => $reserved, 'logged_http_attempts' => count($requests), 'unobserved_reserved_tokens' => $reserved - count($requests),
    'statuses' => $statuses, 'purposes' => $purposes, 'campaign_http_attempts' => $contexts, 'inline_retry_attempts' => $retryCount,
    'minimum_request_start_interval_seconds' => $intervals === [] ? null : min($intervals), 'pacing_violations_below_2s' => count(array_filter($intervals, fn ($value) => $value < 2)),
    'same_flow_cache_hits' => $cacheHits, 'repeated_identical_get_url_sha' => $duplicateGet,
    'duplicate_note' => 'Repeated URL/SHA across separate source/alias/control observations is not automatically avoidable or stale forever.',
    'request_budget_maximum' => 15000, 'all_requests_within_budget' => $reserved <= 15000 && $reserved >= count($requests),
    'collection_requests_per_new_canonical' => $newCount ? ($contexts['p6-corpus-1000-20261029'] ?? 0) / $newCount : null,
    'all_p6_requests_per_new_canonical' => $newCount ? count($requests) / $newCount : null];
p6Save('http_statistics', $http);
$availability = ['available' => 0, 'not_available' => 0, 'unknown' => 0, 'available_but_failed_or_missing' => 0,
    'legacy_unassessed_documents' => 0, 'document_coverage' => [], 'document_quality' => [], 'legal_status' => []];
foreach (Document::with('contents')->orderBy('id')->get() as $document) {
    $map = $document->metadata['language_availability'] ?? [];
    $availability['legacy_unassessed_documents'] += (int) ($map === []);
    foreach ($map as $language => $entry) {
        $availability[$entry['status']]++;
        if ($entry['status'] === 'available' && (! $document->contents->contains('language', $language)
            || ($document->metadata['validation']['languages'][$language]['quality_status'] ?? null) === 'extraction_failed')) {
            $availability['available_but_failed_or_missing']++;
        }
    }
    foreach (['document_coverage' => $document->language_coverage_status, 'document_quality' => $document->quality_status, 'legal_status' => $document->status->value] as $key => $value) {
        $availability[$key][$value] = ($availability[$key][$value] ?? 0) + 1;
    }
}
p6Save('language_statistics', $availability);
$quality = ['all_contents' => $audit['quality_counts'], 'new_content_representations' => $newContents->count(),
    'new_contents_quality' => $newContents->countBy('quality_status')->all(), 'new_contents_languages' => $newContents->countBy('language')->all(),
    'new_contents_formats' => $newContents->countBy(fn ($content) => $content->extraction_metadata['quality']['format'] ?? 'unknown')->all(),
    'protected_candidates' => DocumentContent::whereNotNull('pending_candidate')->count(), 'review_reasons' => []];
$quality['execution_language_outcomes'] = ['stored' => 0, 'candidate' => 0, 'unchanged' => 0, 'extraction_failed' => 0, 'stale_ignored' => 0];
foreach ($items as $item) {
    foreach ($item->language_results ?? [] as $languageResult) {
        $outcome = $languageResult['persistence'] ?? 'not_applicable';
        $quality['execution_language_outcomes'][$outcome] = ($quality['execution_language_outcomes'][$outcome] ?? 0) + 1;
    }
}
foreach (DocumentContent::where('quality_status', 'needs_review')->cursor() as $content) {
    foreach ($content->quality_issues as $issue) {
        $quality['review_reasons'][$issue] = ($quality['review_reasons'][$issue] ?? 0) + 1;
    }
}
p6Save('quality_statistics', $quality);
$pdf = [];
if (is_file(__DIR__.'/pdf_ocr_verification.json')) {
    foreach (p6Read('pdf_ocr_verification') as $entry) {
        foreach ($entry['samples'] as $sample) {
            $sample['archive_status'] = $entry['archive']['archived'] ? 'archived' : 'disabled';
            $pdf[] = $sample;
        }
    }
}
foreach ($items as $item) {
    foreach ($item->outcome['quality'] ?? [] as $language => $entry) {
        if (! in_array($entry['format'] ?? null, ['pdf', 'ocr'], true)) {
            continue;
        }
        $languageOutcome = $item->language_results[$language] ?? [];
        $hash = $entry['provenance']['source_sha256'] ?? null;
        $content = $item->document_id ? DocumentContent::where('document_id', $item->document_id)->where('language', $language)->first() : null;
        $success = in_array($languageOutcome['quality_status'] ?? null, ['verified', 'needs_review'], true)
            && ($entry['validation_levels']['text_extraction_nonempty'] ?? false);
        $pdf[] = ['source_id' => $item->source_external_id, 'canonical_document_id' => $item->document_id,
            'run_item_id' => $item->id, 'source_format' => $entry['format'], 'sha256' => $hash,
            'file_size' => $bytesByHash[$hash] ?? null, 'page_count' => $entry['pages_expected'] ?? null,
            'ocr_pages' => $entry['ocr_pages'] ?? [], 'language' => $language,
            'text_char_count' => array_sum(array_column($entry['page_stats'] ?? [], 'chars')),
            'text_char_count_kind' => 'sum of extracted page text, excluding Markdown page comments',
            'extraction_outcome' => $success ? 'succeeded' : 'failed',
            'quality_status' => $success ? $languageOutcome['quality_status'] : null,
            'quality_issues' => $languageOutcome['quality_issues'] ?? $entry['issues'] ?? [], 'timings' => $entry['timings'] ?? [],
            'persistence_status' => $languageOutcome['persistence'] ?? 'not_persisted', 'content_id' => $content?->id,
            'archive_status' => ($item->outcome['archive']['archived'] ?? 0) > 0 ? 'archived' : 'disabled'];
    }
}
$unique = [];
foreach ($pdf as $sample) {
    if (($sample['sha256'] ?? null) !== null && ($sample['extraction_outcome'] ?? null) === 'succeeded') {
        $unique[$sample['sha256']] = $sample;
    }
}
$pdfReport = ['observations' => $pdf, 'distinct_successful_pdf_sha256' => count($unique),
    'text_layer_pdf_count' => count(array_filter($unique, fn ($sample) => $sample['source_format'] === 'pdf')),
    'scanned_ocr_pdf_count' => count(array_filter($unique, fn ($sample) => $sample['source_format'] === 'ocr')),
    'text_pdf_sampling_goal' => 15, 'scanned_pdf_sampling_goal' => 5,
    'certification' => 'Automated extraction and diagnostic coverage only; no human OCR accuracy certification. Alternate diagnostic renditions are not additional stored content or canonical documents.'];
p6Save('extraction_statistics', $pdfReport);
$discovery = [];
$completeLeaves = 0;
$incompleteLeaves = 0;
$sourceIds = [];
foreach ($campaigns as $campaign) {
    foreach ($campaign->checkpoints as $checkpoint) {
        foreach ($checkpoint->state['nodes'] ?? [] as $node) {
            foreach ($node['rows'] ?? [] as $row) {
                $sourceIds[(string) $row['id']] = true;
            }
            if (! isset($node['children'])) {
                $completeLeaves += (int) ($node['complete'] ?? false);
                $incompleteLeaves += (int) ! ($node['complete'] ?? false);
            }
        }
        if (($checkpoint->state['nodes'] ?? []) !== []) {
            $discovery[] = ['campaign_id' => $campaign->campaign_id, 'name' => $checkpoint->name, 'bounds' => $checkpoint->bounds, 'state' => $checkpoint->state];
        }
    }
}
p6Save('discovery_coverage', ['complete_leaf_partitions' => $completeLeaves, 'incomplete_leaf_partitions' => $incompleteLeaves,
    'discovered_unique_source_ids' => count($sourceIds), 'checkpoints' => $discovery, 'global_coverage_confirmed' => false]);
$initialIdentifiers = array_column($baseline['identifiers'], null, 'external_id');
$resolvedIdentifiers = DB::table('document_identifiers')->whereIn('external_id', array_keys($sourceIds))->get();
$discoveryIdentity = ['discovered_source_ids' => count($sourceIds),
    'initially_existing_source_ids' => count(array_intersect_key($sourceIds, $initialIdentifiers)),
    'currently_resolved_source_ids' => $resolvedIdentifiers->count(),
    'resolved_canonical_groups' => $resolvedIdentifiers->pluck('document_id')->unique()->count(),
    'unresolved_source_ids' => count($sourceIds) - $resolvedIdentifiers->count(),
    'selected_resolved_canonical_groups' => $items->pluck('document_id')->filter()->unique()->count(),
    'baseline_existing_canonicals_in_import_items' => $items->pluck('document_id')->filter(fn ($id) => $id !== null && $id <= ($collection?->baseline_max_id ?? 229))->unique()->count(),
    'skipped_alias_items' => $items->where('status', 'skipped_alias')->count(),
    'note' => 'Unfetched source IDs are not guessed canonical groups. Diagnostic-only PDF selections and source IDs/languages are never counted as new canonical documents.'];
p6Save('identity_statistics', $discoveryIdentity);
$diagnostics = [];
foreach ($items as $item) {
    if ($item->status !== 'review' || $item->document_id !== null) {
        continue;
    }
    $diagnostics[] = ['run_item_id' => $item->id, 'run_id' => $item->scrape_run_id, 'source_id' => $item->source_external_id,
        'source_url' => 'https://lex.uz/uz/docs/'.$item->source_external_id, 'recorded_at' => $item->finished_at?->toAtomString(),
        'import_outcome' => $item->outcome['import_outcome'] ?? null, 'identity_verified' => $item->outcome['identity_verified'] ?? false,
        'review_reasons' => $item->review_reasons, 'language_results' => $item->language_results,
        'currently_resolved_document_id' => DB::table('document_identifiers')->where('external_id', $item->source_external_id)->value('document_id')];
}
p6Save('review_diagnostics', ['items' => $diagnostics,
    'note' => 'Historical run-item observations, not unique unresolved documents. The two extraction_failed import outcomes were metadata-only entry graphs with no usable body: one inconclusive and one explicit translation notice. Their language outcomes remain not_attempted, not confirmed-available extraction failures. The earlier script-label guard receipts are retained even after successful bounded re-import. No failed queue job or fake content was created.']);
$partitionSummary = [];
foreach ($discovery as $checkpoint) {
    foreach ($checkpoint['state']['nodes'] as $node) {
        if (($node['depth'] ?? null) !== 0) {
            continue;
        }
        $union = [];
        $leaves = 0;
        foreach ($checkpoint['state']['nodes'] as $child) {
            foreach ($child['rows'] ?? [] as $row) {
                $union[(string) $row['id']] = true;
            }
            $leaves += (int) ! isset($child['children']);
        }
        $partitionSummary[] = ['checkpoint' => $checkpoint['name'], 'query' => $node['query'], 'listing_total' => $node['total'] ?? null,
            'discovered_unique_source_ids' => count($union), 'complete' => $node['complete'] ?? false,
            'nodes' => count($checkpoint['state']['nodes']), 'leaf_partitions' => $leaves];
    }
}
p6Save('discovery_partition_summary', ['partitions' => $partitionSummary, 'global_coverage_confirmed' => false]);
$profiles = [];
if (is_file(__DIR__.'/worker_profiles.jsonl')) {
    foreach (new SplFileObject(__DIR__.'/worker_profiles.jsonl') as $line) {
        if (trim($line) !== '') {
            $profiles[] = json_decode($line, true);
        }
    }
}
$invocations = [];
if (is_file(__DIR__.'/controller_invocations.jsonl')) {
    foreach (new SplFileObject(__DIR__.'/controller_invocations.jsonl') as $line) {
        if (trim($line) !== '') {
            $entry = json_decode($line, true);
            $invocations[hash('sha256', json_encode($entry))] = $entry;
        }
    }
}
foreach (['validation_execution_before_resume', 'validation_execution', 'collection_execution'] as $name) {
    if (is_file(__DIR__.'/'.$name.'.json')) {
        $entry = p6Read($name);
        $invocations[hash('sha256', json_encode($entry))] = $entry;
    }
}
$collectionSeconds = $collection ? 0 : null;
$validationSeconds = 0;
foreach ($invocations as $entry) {
    if (($entry['campaign']['id'] ?? null) === 'p6-corpus-1000-20261029') {
        $collectionSeconds += $entry['controller_wall_seconds'];
    } elseif (($entry['action'] ?? null) === 'execute') {
        $validationSeconds += $entry['controller_wall_seconds'];
    }
}
$policySeconds = is_file(__DIR__.'/policy_execution.json') ? p6Read('policy_execution')['controller_wall_seconds'] : 0;
$pacing = 0.0;
if (is_file(__DIR__.'/pacer_waits.jsonl')) {
    foreach (new SplFileObject(__DIR__.'/pacer_waits.jsonl') as $line) {
        if (trim($line) !== '') {
            $pacing += json_decode($line, true)['seconds'];
        }
    }
}
$performance = ['validation_active_controller_seconds' => $validationSeconds, 'policy_active_controller_seconds' => $policySeconds,
    'collection_active_controller_seconds' => $collectionSeconds, 'collection_worker_seconds' => array_sum(array_column($profiles, 'wall_seconds')),
    'collection_pacer_wait_seconds' => $pacing, 'all_http_network_and_reservation_observation_seconds' => $networkSeconds,
    'collection_http_callback_seconds' => $collectionHttpSeconds,
    'worker_database_query_ms' => array_sum(array_column($profiles, 'query_ms')),
    'peak_worker_php_bytes' => $profiles === [] ? null : max(array_column($profiles, 'peak_memory')),
    'largest_observed_child_process_max_rss_kib' => $profiles === [] ? null : max(array_map(fn ($profile) => $profile['child_usage']['ru_maxrss'] ?? 0, $profiles)),
    'child_memory_note' => 'Linux getrusage(RUSAGE_CHILDREN) maximum resident set, KiB; largest observed child process, not summed concurrent extractor memory or a per-document peak.',
    'new_canonical_per_collection_hour' => $newCount && $collectionSeconds ? $newCount * 3600 / $collectionSeconds : null,
    'new_content_representations_per_collection_hour' => $newContents->count() && $collectionSeconds ? $newContents->count() * 3600 / $collectionSeconds : null,
    'timing_note' => 'Controller active durations exclude operator gaps. Worker duration contains pacing/network/local extraction/database. These timings overlap and must not be summed. Queue-wait sums are not elapsed processing time. See individual invocation and worker records.',
    'projections' => []];
if ($collection) {
    $end = $collection->completed_at ?? $collection->stopped_at ?? ($collection->status === 'paused' ? $collection->paused_at : now());
    $wall = $collection->started_at->diffInSeconds($end, true);
    $performance['collection_end_to_end_wall_seconds'] = $wall;
    $performance['collection_operator_or_noncontroller_gap_seconds'] = max(0, $wall - $collectionSeconds);
    $performance['gap_note'] = 'Wall minus active invocations estimates controlled operator pauses and small process-boundary overhead; timestamps have second precision. This gap is not scraper processing time.';
}
$queueWaitSeconds = 0.0;
if (is_file(__DIR__.'/queue_recovery_waits.jsonl')) {
    foreach (new SplFileObject(__DIR__.'/queue_recovery_waits.jsonl') as $line) {
        if (trim($line) !== '') {
            $queueWaitSeconds += json_decode($line, true)['seconds'];
        }
    }
}
$performance['collection_recovery_queue_wait_seconds'] = $queueWaitSeconds;
$performance['http_timing_note'] = 'HTTP callback wall includes socket time plus short response observation/hash/provenance work; it is not a pure network-only measure. PDF parse time already includes OCR time.';
$parseTimes = ['html_parse_seconds' => [], 'pdf_parse_seconds' => [], 'ocr_seconds' => [], 'queue_wait_seconds' => [], 'import_and_reconcile_estimate_seconds' => []];
foreach ($items as $item) {
    foreach ($item->outcome['quality'] ?? [] as $qualityEntry) {
        foreach ($qualityEntry['timings'] ?? [] as $key => $seconds) {
            if (isset($parseTimes[$key]) && $seconds > 0) {
                $parseTimes[$key][] = $seconds;
            }
        }
    }
    $metrics = $item->outcome['metrics'] ?? [];
    if (isset($metrics['queue_wait_seconds'])) {
        $parseTimes['queue_wait_seconds'][] = $metrics['queue_wait_seconds'];
    }
    if (isset($metrics['fetch_parse_import_seconds'], $metrics['fetch_parse_seconds'])) {
        $parseTimes['import_and_reconcile_estimate_seconds'][] = max(0, $metrics['fetch_parse_import_seconds'] - $metrics['fetch_parse_seconds']);
    }
}
foreach ($parseTimes as $key => $values) {
    sort($values);
    $performance[$key] = ['observations' => count($values), 'sum' => array_sum($values),
        'p50' => $values === [] ? null : $values[(int) floor((count($values) - 1) * 0.5)],
        'p95' => $values === [] ? null : $values[(int) floor((count($values) - 1) * 0.95)]];
}
if ($newCount && $collectionSeconds) {
    foreach ([1000, 10000, 100000] as $target) {
        $performance['projections'][$target] = ['same_mix_collection_hours' => $collectionSeconds / $newCount * $target / 3600,
            'same_mix_collection_requests' => (int) ceil(($contexts['p6-corpus-1000-20261029'] ?? 0) / $newCount * $target),
            'planning_hours_1x_to_3x' => [$collectionSeconds / $newCount * $target / 3600, 3 * $collectionSeconds / $newCount * $target / 3600],
            'uncertainty' => 'Planning estimate only. Format/OCR mix, discovery alias yield, source drift, retries and archive policy can materially increase costs. Access permission and separate execution authorization still required.'];
    }
}
p6Save('performance', $performance);
$storage = ['at' => $audit['at'], 'database_bytes' => $audit['database_bytes'], 'disk_free_bytes' => $audit['disk_free_bytes'],
    'archive' => $audit['archive'], 'relations' => [], 'stage_observations' => [], 'projections' => [],
    'limitations' => 'Relation-level sizes were not captured at earlier stage boundaries. Current sizes are real observations, not reconstructed historical measurements. PostgreSQL physical growth includes indexes, TOAST, dead tuples, cache/queue/diagnostic history and allocation effects; it is not pure corpus text growth. Raw retention disabled: zero archive growth cannot project future retained HTML/PDF storage. No automatic cleanup performed.'];
foreach (DB::select('SELECT relname,pg_total_relation_size(relid) AS total_bytes,pg_relation_size(relid) AS heap_bytes FROM pg_catalog.pg_statio_user_tables ORDER BY relname') as $relation) {
    $storage['relations'][$relation->relname] = ['total_bytes' => (int) $relation->total_bytes, 'heap_bytes' => (int) $relation->heap_bytes];
}
$storageStart = p6Read('pre_collection_integrity');
$storage['before_collection'] = ['at' => $storageStart['at'], 'database_bytes' => $storageStart['database_bytes'], 'disk_free_bytes' => $storageStart['disk_free_bytes']];
$storage['database_growth_bytes'] = $audit['database_bytes'] - $storageStart['database_bytes'];
$storage['database_growth_per_new_canonical_bytes'] = $newCount ? $storage['database_growth_bytes'] / $newCount : null;
foreach ([30, 100, 300, 600, 1000] as $stage) {
    if (is_file(__DIR__.'/stage_'.$stage.'.json')) {
        $observation = p6Read('stage_'.$stage);
        $storage['stage_observations'][$stage] = ['at' => $observation['at'], 'database_bytes' => $observation['database_bytes'],
            'disk_free_bytes' => $observation['disk_free_bytes'], 'archive' => $observation['archive']];
    }
}
if ($newCount) {
    foreach ([10000, 100000] as $target) {
        $storage['projections'][$target] = ['same_mix_incremental_database_bytes' => (int) round($storage['database_growth_per_new_canonical_bytes'] * $target),
            'archive_bytes' => null, 'uncertainty' => 'Planning estimate only; database allocation, diagnostic retention and future PDF/OCR/archive mix can materially change growth. Archive retention needs a separately measured permitted sample.'];
    }
}
p6Save('storage_statistics', $storage);
$stageResults = [];
$previousNew = 0;
$previousUsed = 0;
$previousEnd = $collection?->started_at?->getTimestamp() ?? 0;
foreach ([30, 100, 300, 600, 1000] as $stage) {
    if (! is_file(__DIR__.'/stage_'.$stage.'.json')) {
        continue;
    }
    $observation = p6Read('stage_'.$stage);
    $end = strtotime($observation['at']);
    $stageItems = $items->filter(fn ($item) => $item->finished_at !== null && $item->finished_at->getTimestamp() <= $end);
    $activeSeconds = 0.0;
    foreach ($invocations as $invocation) {
        if (($invocation['campaign']['id'] ?? null) !== 'p6-corpus-1000-20261029') {
            continue;
        }
        $invocationEnd = strtotime($invocation['finished_at'] ?? $invocation['at']);
        $invocationStart = $invocationEnd - $invocation['controller_wall_seconds'];
        $activeSeconds += max(0, min($end, $invocationEnd) - max($previousEnd, $invocationStart));
    }
    $stageResults[$stage] = ['at' => $observation['at'], 'cumulative_new_canonical' => $observation['campaign']['new_canonical_documents'],
        'stage_new_canonical' => $observation['campaign']['new_canonical_documents'] - $previousNew,
        'cumulative_selected_source_ids' => $observation['campaign']['selected_source_count'],
        'cumulative_existing_canonical_in_items' => $stageItems->pluck('document_id')->filter(fn ($id) => $id !== null && $id <= $collection->baseline_max_id)->unique()->count(),
        'cumulative_skipped_alias_items' => $stageItems->where('status', 'skipped_alias')->count(),
        'cumulative_new_contents' => $observation['rows']['document_contents'] - $baseline['rows']['document_contents'],
        'cumulative_new_verified' => ($observation['quality_counts']['verified'] ?? 0) - ($baseline['quality_counts']['verified'] ?? 0),
        'cumulative_new_needs_review' => ($observation['quality_counts']['needs_review'] ?? 0) - ($baseline['quality_counts']['needs_review'] ?? 0),
        'cumulative_technical_failures' => $stageItems->where('status', 'failed')->count(),
        'cumulative_guard_rejected_items' => $stageItems->filter(fn ($item) => $item->status === 'review' && $item->document_id === null)->count(),
        'cumulative_http_reserved' => $observation['campaign']['used'], 'stage_http_reserved' => $observation['campaign']['used'] - $previousUsed,
        'stage_active_controller_seconds' => $activeSeconds, 'stage_wall_seconds' => $end - $previousEnd,
        'integrity_errors' => $observation['errors'], 'queue_jobs' => $observation['rows']['jobs']];
    $previousNew = $observation['campaign']['new_canonical_documents'];
    $previousUsed = $observation['campaign']['used'];
    $previousEnd = $end;
}
p6Save('stage_statistics', ['stages' => $stageResults, 'note' => 'Cumulative figures include explicit repeat controls and retained guard rejections. Stage active time is the overlap of recorded controller invocations with stage boundaries; wall time also includes controlled operator pauses. Counts are not global source coverage.']);
$summary = ['at' => now()->toAtomString(), 'baseline_documents' => $baseline['rows']['documents'], 'p6_new_canonical' => $newCount,
    'target' => 1000, 'target_reached' => $newCount === 1000, 'total_documents' => $audit['rows']['documents'],
    'total_identifiers' => $audit['rows']['document_identifiers'], 'total_contents' => $audit['rows']['document_contents'],
    'quality_counts' => $audit['quality_counts'], 'new_content_representations' => $newContents->count(),
    'run_item_outcomes' => $items->countBy('status')->all(), 'technical_failures' => $items->where('status', 'failed')->count(),
    'extraction_failed_import_items' => $items->filter(fn ($item) => ($item->outcome['import_outcome'] ?? null) === 'extraction_failed')->count(),
    'guard_rejected_source_items' => $items->filter(fn ($item) => $item->status === 'review' && $item->document_id === null)->count(),
    'historical_failed_jobs' => $baseline['rows']['failed_jobs'], 'new_failed_jobs' => $audit['rows']['failed_jobs'] - $baseline['rows']['failed_jobs'],
    'selected_source_ids' => $campaigns->sum('selected_source_count'), 'http' => $http, 'language' => $availability,
    'pdf_text_layer' => $pdfReport['text_layer_pdf_count'], 'pdf_scanned_ocr' => $pdfReport['scanned_ocr_pdf_count'],
    'archive' => app(SourceArchive::class)->audit(), 'archive_observation_statuses' => DB::table('source_archive_observations')->selectRaw('archive_status,count(*) AS n')->groupBy('archive_status')->pluck('n', 'archive_status')->all(),
    'integrity_passed' => $audit['errors'] === [], 'campaigns' => $campaigns->toArray(),
    'discovery_identity' => $discoveryIdentity,
    'recommendation' => $newCount === 1000 && $collection?->status === 'completed' && $audit['errors'] === []
        && $http['all_requests_within_budget'] && $http['pacing_violations_below_2s'] === 0 ? 'CONDITIONAL GO' : 'NO-GO'];
p6Save('final_summary', $summary);
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
