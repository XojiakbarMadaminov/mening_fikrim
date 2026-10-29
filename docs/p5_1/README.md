# P5.1 — completed controlled follow-up

Report: [P5_1_REPORT](../P5_1_REPORT.md). No P6/1000/full crawl/schedule/deployment was started.

Actual P5 baseline187 documents/509 identifiers/433 contents (344verified/89needs_review),29runs/532items,24historical failed jobs,0jobs,9migrations. Final229documents/625identifiers/541contents (449verified/92needs_review),45runs/644items,24historicalfailedjobs,0jobs. All433originaltext hashes/quality,canonical/identifier IDs,old P5 cache state/ledger and643historical evidence files unchanged.

P5 additional158+P5.1 additional42=200 NEW canonical. New108contents:105verified/3needs_review. New translations/canonical aliases/existing documents are not counted toward42.112run items,59alias skips;114distinct selected entry IDs including5diagnostic-only IDs and explicit controls. Original stopped P5 campaign was not reopened or changed.

Limits declared before live:campaign `p5-1-20261029`,42NEW target,20→42 audit gates,180distinct selected entry IDs,1800HTTP attempts,one controller/worker,>=2000ms,per-run30/per-context400. Source planning:42×(360/158)≈96+24controls+60headroom=180. HTTP allocation:350discovery+1080collection+120targeted/repeat+250retry/drift=1800,planning assumptions only. Actual114/180selected and414/1800HTTP;all200,min2.000140510s,no retries/newtechnicalfailures. Controller882.156s (14m42s),worker590.811s;no operator pause in controller. Stop:`approved_target_reached`.

Discovery only oliy/statusY/2020 bounded partition:1429listing IDs/1429unique,15leaf/29reconcilednodes,0unresolved incomplete. Pagecap8/depth10/partitioncap63 retained. Completed checkpoint repeat0requests;fresh leaf replay stable. No global coverage claim.

Availability independent from quality in existing document JSONB;no migration/new table. Observed118available/70unknown/0confirmed-not_available/0available_failed across47freshly checked documents.182legacy documents remain unassessed by new availability evidence. No absent/unknown translations are stored as content. Confirmed absence has retained actual P0 notice tested offline plus synthetic persistence cases;available_failed is synthetic regression only. The P5.1 live gap is explicit. Two existing real PDF/OCR samples were re-extracted unchanged;new unique diversity/manual certification remains open.

Executed commands (campaign already exists and is stopped;do not rerun creation):

```bash
php artisan documents:pilot followup --after=p5-20261028 --id=p5-1-20261029 --requests=1800 --sources=180
SCRAPING_LIVE_ENABLED=true php docs/p5_1/pilot.php execute
```

Current read-only/offline commands:

```bash
php artisan documents:pilot status
php artisan documents:sync lex --report=45
php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run
vendor/bin/phpunit
vendor/bin/pint --test
```

Driver execute resumes only an open matching campaign with original caps/checkpoints. The present terminal stop refuses further traffic;do not clear cache/reset budget. Further collection requires separately authorized P6 scope.

Evidence index:

- Baseline/preflight:database_baseline.json,stage_a_preflight.json,stage_a_results.json,campaign_created.json,historical_baseline.json.
- Stages:stage_b_results.json,stage_c_results.json,stage_d_results.json,after_run_30..45.json,run_30..45.json,run_statistics.json.
- Coverage:discovery_coverage.json,partition_statistics.json,discovery_cached_repeat.json,discovery_fresh_replay.json,sampling_pool.json.
- Identity/language:source_identity_evidence.json,language_label_evidence.json,identity_diagnostics.json,language_availability.json,representative_examples.json,documents.json.
- Quality/PDF:quality_statistics.json,pdf_ocr_verification.json,extraction_statistics.json.
- Reliability:interruption_resume.json,repeat_import.json,integrity_audit.json,database_final.json,historical_integrity.json,final_checks.json.
- Performance:requests.jsonl,pacer_waits.jsonl,worker_profiles.json,performance.json,http_statistics.json,storage_statistics.json,execution.json.
- Tests:baseline143/619,prelive166/696,final172/719;JUnit/text logs,final_pint.txt,P0offline16/P0.1offline37. Initial test/helper failures are retained;final failures/errors/skips0.
- Final:final_summary.json,changed_files.json,live_terminal.txt,terminal_summary.txt.

Raw HTML/PDF bodies,cookies/Viewstate and credentials are not archived. Parsed card/link values,response/source hashes,raw labels,OCR/layout diagnostics andtimestamps are retained. Backup of prechange code is local ignored storage/app/p5_1_baseline/code.tar.gz. Five source identity IDs remain manual review;no number transliteration/reciprocal-link bypass. Recommendation:P6 CONDITIONAL GO with separate authorization and explicit PDF/OCR/access/durability gates.
