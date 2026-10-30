<?php

/** Documentation only: requires completed machine reports; never contacts Lex.uz or writes business data. */
function evidence(string $name): array
{
    return json_decode(file_get_contents(__DIR__.'/'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
}
function duration(float $seconds): string
{
    return sprintf('%dh %02dm %02ds', (int) floor($seconds / 3600), (int) floor($seconds / 60) % 60, (int) round($seconds) % 60);
}
$summary = evidence('final_summary');
$performance = evidence('performance');
$coverage = evidence('discovery_coverage');
$quality = evidence('quality_statistics');
$storage = evidence('storage_statistics');
$partitions = evidence('discovery_partition_summary');
$audit = evidence('final_integrity');
$qa = evidence('human_qa_manifest');
$campaign = end($summary['campaigns']);
if (! in_array($campaign['status'], ['completed', 'stopped', 'failed'], true)) {
    throw new RuntimeException('Do not finalize a running or recoverably paused campaign.');
}
$completed = $summary['target_reached'] && $campaign['status'] === 'completed';
$xml = simplexml_load_file(__DIR__.'/full_junit.xml');
$suite = $xml->xpath('/testsuites/testsuite')[0];
$tests = (int) $suite['tests'];
$assertions = (int) $suite['assertions'];
$testErrors = (int) $suite['errors'] + (int) $suite['failures'];
$pint = json_decode(trim(file_get_contents(__DIR__.'/pint_test.txt')), true)['result'] ?? 'unknown';
$http = $summary['http'];
$language = $summary['language'];
$active = (float) ($performance['collection_active_controller_seconds'] ?? 0);
$validation = $performance['validation_active_controller_seconds'] + $performance['policy_active_controller_seconds'];
$status = $completed ? 'TARGET REACHED; '.$summary['recommendation'].' with documented coverage/retention limits' : 'STOPPED; '.$summary['p6_new_canonical'].' / 1000 NEW canonicals; '.($campaign['stop_reason'] ?? 'terminal gates not satisfied');
$rows = [
    'P6 NEW canonical documents' => $summary['p6_new_canonical'].' / 1000',
    'Starting documents / contents' => $summary['baseline_documents'].' / 541',
    'Total documents / identifiers / contents' => $summary['total_documents'].' / '.$summary['total_identifiers'].' / '.$summary['total_contents'],
    'Verified / needs_review contents' => ($summary['quality_counts']['verified'] ?? 0).' / '.($summary['quality_counts']['needs_review'] ?? 0),
    'New content representations' => $summary['new_content_representations'],
    'All P6 HTTP attempts / reserved tokens' => $http['logged_http_attempts'].' / '.$http['reserved_requests'],
    'Validation / main HTTP attempts' => ($http['campaign_http_attempts']['p6-validation-20261029'] ?? 0).' / '.($http['campaign_http_attempts']['p6-corpus-1000-20261029'] ?? 0),
    'Main selected source IDs / maximum' => $campaign['selected_source_count'].' / '.$campaign['max_source_selections'],
    'Minimum actual request-start interval' => number_format($http['minimum_request_start_interval_seconds'] ?? 0, 6).' seconds',
    'Inline HTTP retries / new failed queue jobs' => $http['inline_retry_attempts'].' / '.$summary['new_failed_jobs'],
    'HTTP non-2xx status observations' => json_encode(array_filter($http['statuses'], fn ($count, $code) => (int) $code < 200 || (int) $code >= 300, ARRAY_FILTER_USE_BOTH)),
    'Technical failed import items' => $summary['technical_failures'],
    'No-usable-body import outcomes / retained guard review items' => $summary['extraction_failed_import_items'].' / '.$summary['guard_rejected_source_items'],
    'Main active controller duration' => duration($active),
    'Main end-to-end wall / operator or noncontroller gap' => duration($performance['collection_end_to_end_wall_seconds'] ?? 0).' / '.duration($performance['collection_operator_or_noncontroller_gap_seconds'] ?? 0),
    'Validation active duration (including policy)' => duration($validation),
    'Available language observations' => $language['available'],
    'Confirmed unavailable / unknown language observations' => $language['not_available'].' / '.$language['unknown'],
    'Available but failed/missing / legacy unassessed documents' => $language['available_but_failed_or_missing'].' / '.$language['legacy_unassessed_documents'],
    'Complete / incomplete discovery leaves' => $coverage['complete_leaf_partitions'].' / '.$coverage['incomplete_leaf_partitions'],
    'Discovered unique source IDs' => $coverage['discovered_unique_source_ids'],
    'Distinct text PDF / OCR PDF samples' => $summary['pdf_text_layer'].' / '.$summary['pdf_scanned_ocr'],
    'Archived blobs / bytes' => $summary['archive']['blobs'].' / '.$summary['archive']['registered_bytes'],
    'Database physical growth / bytes per NEW canonical' => $storage['database_growth_bytes'].' / '.number_format($storage['database_growth_per_new_canonical_bytes'] ?? 0, 2),
    'Historical failed jobs retained' => $summary['historical_failed_jobs'],
    'Integrity / budget / pacing gates' => ($summary['integrity_passed'] ? 'PASS' : 'FAIL').' / '.($http['all_requests_within_budget'] ? 'PASS' : 'FAIL').' / '.($http['pacing_violations_below_2s'] === 0 ? 'PASS' : 'FAIL'),
    'Tests / assertions / failures or errors / Pint' => "$tests / $assertions / $testErrors / $pint",
    'Human QA preparation' => $qa['count'].' distinct documents; review pending',
    'Campaign status / reason' => $campaign['status'].' / '.($campaign['stop_reason'] ?? 'none'),
    'Recommendation' => $summary['recommendation'],
];
$block = 'Actual terminal results; generated '.$summary['at'].".\n\n| Metric | Actual result |\n|---|---|\n";
foreach ($rows as $name => $value) {
    $block .= '| '.$name.' | '.str_replace('|', '\\|', (string) $value)." |\n";
}
$block .= "\n### Executed stages\n\n| NEW canonical gate | Documents | Contents | Verified | Review | HTTP reserved | Integrity |\n|---:|---:|---:|---:|---:|---:|---|\n";
foreach ([30, 100, 300, 600, 1000] as $stage) {
    $path = __DIR__.'/stage_'.$stage.'.json';
    if (! is_file($path)) {
        $block .= '| '.$stage." | Not reached | — | — | — | — | Not claimed |\n";

        continue;
    }
    $data = evidence('stage_'.$stage);
    $block .= '| '.$stage.' | '.$data['rows']['documents'].' | '.$data['rows']['document_contents'].' | '.($data['quality_counts']['verified'] ?? 0).' | '.($data['quality_counts']['needs_review'] ?? 0).' | '.$data['campaign']['used'].' | '.($data['errors'] === [] ? 'PASS' : 'FAIL')." |\n";
}
$block .= "\n### Actual performance and projections\n\n";
$block .= 'Main requests per NEW canonical: '.number_format($http['collection_requests_per_new_canonical'] ?? 0, 3).'; all-P6 overhead included: '.number_format($http['all_p6_requests_per_new_canonical'] ?? 0, 3).'. ';
$block .= 'Main active throughput: '.number_format($performance['new_canonical_per_collection_hour'] ?? 0, 2)." new documents/hour. These are real pilot measurements, not the offline alias benchmark. Different source/date/format mixes prevent a direct causal speed comparison with P5.\n\n";
$block .= "| Planning target | Same-mix hours | Same-mix HTTP |\n|---:|---:|---:|\n";
foreach ($performance['projections'] as $target => $projection) {
    $block .= '| '.$target.' | '.number_format($projection['same_mix_collection_hours'], 2).' | '.$projection['same_mix_collection_requests']." |\n";
}
$block .= "\nPlanning estimates only. OCR-heavy or failed-scan workloads can be much slower; discovery overhead, retries, source aliases and future access/retention conditions can change. PDF parse timings include OCR. See performance.json for pacing/HTTP/query/queue/memory details and uncertainty.\n\n";
$block .= "### Storage measurements\n\n";
$block .= 'Database before collection / final: '.$storage['before_collection']['database_bytes'].' / '.$storage['database_bytes'].' bytes; final free disk: '.$storage['disk_free_bytes']." bytes. Physical database growth includes diagnostic history and allocation effects. Earlier stage database/archive/free-disk observations are retained; per-relation sizes were measured only at final reporting, so no historical per-table growth is claimed.\n\n";
foreach ($storage['projections'] as $target => $projection) {
    $block .= '- '.$target.' documents: approximately '.number_format($projection['same_mix_incremental_database_bytes'] / 1048576, 2)." MiB incremental database growth at the observed mix.\n";
}
$block .= "\nThese are planning estimates. Live archive bytes stayed zero because retention was disabled; future original-file storage cannot be estimated from this zero-retention pilot. See [storage_statistics.json](p6/storage_statistics.json) for relation sizes and limitations.\n\n";
$block .= "### Bounded discovery coverage\n\n| Root | Inclusive dates | Listing count | Unique source IDs | Complete |\n|---|---|---:|---:|---|\n";
foreach ($partitions['partitions'] as $partition) {
    $query = $partition['query'];
    $block .= '| '.$query['section'].' | '.$query['from'].' to '.$query['to'].' | '.$partition['listing_total'].' | '.$partition['discovered_unique_source_ids'].' | '.($partition['complete'] ? 'yes' : 'no')." |\n";
}
$block .= "\nAll 12 fixed queries were reconciled. Cross-partition/category overlap is deduplicated: the total unique source count is not the sum of listings and is not a canonical count. The fixed scope had no additional eligible selections after known identifiers and selected sources were excluded. No scope expansion, budget reset or terminal resume was attempted. Global coverage is not established.\n\n";
$block .= "### Evidence and remaining gaps\n\n";
$block .= "Raw retention stayed disabled: zero live original files is an explicit permission/coverage limitation. Offline HTML/PDF replay was verified using labelled regression fixtures; the real archive dry-run selected zero graphs. PDF/OCR sampling goals were 15 text / 5 OCR; actual counts above are not accuracy certification. Human review has not been performed. Unknown availability remains uncertainty, not confirmed absence. Global Lex.uz coverage, inactive/undated/revision completeness and production permission are not established.\n\n";
$block .= "The two no-usable-body import outcomes were metadata-only entry graphs (4774598 inconclusive; 6576817 explicit translation notice). Existing whole-import diagnostics call these extraction_failed because no usable body was obtained. Their language outcomes remain not_attempted; no confirmed-available translation extraction failure, fake content or failed queue job is inferred. Guard review receipts also include earlier label failures subsequently resolved by bounded re-import; they are not a count of unique unresolved documents. See [review_diagnostics.json](p6/review_diagnostics.json).\n\n";
$block .= "Machine evidence: [final_summary.json](p6/final_summary.json), [stage statistics including aliases/existing/new/quality/HTTP/active time](p6/stage_statistics.json), [integrity](p6/final_integrity.json), [discovery](p6/discovery_coverage.json), [quality](p6/quality_statistics.json), [language](p6/language_statistics.json), [extraction](p6/extraction_statistics.json), [performance](p6/performance.json), [human QA](p6/human_qa_manifest.json). No larger import or scheduler was started.\n";
$path = dirname(__DIR__).'/P6_REPORT.md';
$report = file_get_contents($path);
$report = preg_replace('/^\*\*Status:.*$/m', '**Status: '.$status.'.**', $report);
$report = preg_replace('/<!-- P6_RESULTS_BEGIN -->.*?<!-- P6_RESULTS_END -->/s', "<!-- P6_RESULTS_BEGIN -->\n".$block."\n<!-- P6_RESULTS_END -->", $report);
$report = str_replace('Final results, stage evidence, integrity and a GO / CONDITIONAL GO / NO-GO recommendation will replace the in-progress block after execution ends.', 'Actual results and the final recommendation are recorded above. Terminal safety stops cannot be bypassed through ordinary resume.', $report);
$temporary = $path.'.part';
file_put_contents($temporary, $report);
rename($temporary, $path);
echo "P6 RESULT\n";
foreach ($rows as $name => $value) {
    echo $name.': '.$value."\n";
}
