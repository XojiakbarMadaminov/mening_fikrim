# P5 controlled corpus pilot

Status: **STOPPED safely**, 2026-10-29T00:17:16+05:00. 158NEWcanonical/370newcontent;final187documents/509identifiers/433contents,344verified+89needs_review. StageB30andC100passed;D158/200partial. Predefined360distinctsource-IDguard exhausted;1229/4000HTTPused (2771remaining), no access/rate/technicalfailure. Do not reset/restart the stopped campaign. Exact200gate remains open (42NEWmissing); next1000pilot NO-GO until bounded supplementary/coverage/identity decisions.

Baseline:29 documents,82 identifiers,63 contents (42verified/21needs_review),10runs/169items,24historical failed jobs,0queued jobs;9migrations applied. Existing130tests/581assertions PASS (`baseline_tests.txt`). Historical P0–P4 SHA manifest:`historical_hashes.json`; ignored implementation backup:`storage/app/p5_baseline`.

Existing flow retained: discovery→database queue/durable receipt→shared paced download→existing HTML/PDF/Tesseract extraction→universal importer identity/status/basic-usability checks→per-language quality classification→verified/needs_review persistence or protected candidate→item reconciliation. P4 quality never blocks usable content; identity/metadata conflicts still fail closed. No parser/schema changes planned.

Finite campaign allowance: **4000 total Lex.uz requests**, all discovery, cards, representations, PDFs, redirects and retries. Allocation for planning:200 discovery/replay +3000 document/alias traffic +600 retries/redirects +200 control/targeted traffic=4000. These are planning allowances, not guaranteed consumption or worst-case cost per document. Actual cap is shared, hard and atomic; 200 documents need not fit in it. At8identity nodes,2HTML/card requests+PDF per node,3attempts andredirects, worst-case document cost can greatly exceed the typical6–10 requests; stop rather than increase budget. Existing per-context400 cap and per-run1..30 remain.

Campaign state uses existing **database cache**, forever entries (no24h budget expiry), attached campaign ID in each run checkpoint. Do not clear cache during a campaign. Missing state fails closed; stopped campaign has no automatic reset/resume path. Source403/429 or2consecutive5xx/network errors latch stop before another attempt; worker exits retaining unfinished receipts. No historical failed jobs are retried or removed.

Stages:30→100→200 **new canonical rows**, relative to baseline max document ID, using existing reciprocal identity/importer uniqueness. Existing documents, identifiers, language variants and repeat imports do not increase the count. One worker,>=2000ms shared start spacing,<=30source IDs per run,<=360attempted source IDs in driver. Stage expansion requires exact prior target, empty queue and integrity audit. Scheduler stays disabled.

Sampling: actual supported primary roots nat/loc/int/tech/court/oliy, bounded date partitions, no language filter. Repeat identical discovery partitions, retain complete/incomplete flags and count/unique-ID evidence; round-robin candidates across partitions, exclude existing identifiers and attempted IDs. Historical2010 national,2020 local/international/court/parliamentary and2026 technical documents are initial strata. All raw source remains transient.

Run driver only after green preflight/full tests. It preserves progress after every partition/run, bounded worker stop/resume, stage and final audits. A stop is terminal for live work in this authorization; do not bypass it for the target.


Final evidence:

- [P5_REPORT](../P5_REPORT.md), [final_summary.json](final_summary.json), [final_terminal.json](final_terminal.json).
- [Discovery coverage](discovery_coverage.json), [partition→corpus mapping](discovery_partition_statistics.json):5completeenumerations;oliy160/1429incomplete,1269records beyondcap.
- [Extraction](extraction_statistics.json):368HTML+1textPDF(34pages)+1naturalOCRPDF(7pages), all370uniqueusablevariants persisted;68newneeds_review. Newlegal156active/2pending;4languages.
- [Quality](quality_statistics.json), [performance/projections](performance.json), [storage](storage_statistics.json).
- [Stage A](stage_a_results.json), [B](stage_b_results.json), [C](stage_c_results.json), [D](stage_d_results.json), [per-runstats](run_statistics.json), [integrity](integrity_audit.json).
- [Workerstop/resume](interruption_resume.json), [discoveryreplay](discovery_resume.json), [repeat](repeat_import.json).
- [HTTP](http_statistics.json), [requests](requests.jsonl), [pacingwaits](pacer_waits.jsonl), [workerprofiles](worker_profiles.json), [live terminal](live_terminal.txt).
- [Baseline tests](baseline_tests.txt), [prelive full tests](prelive_full_tests.txt), [final143/619](final_tests.txt), [JUnit](final_junit.xml), [Pint](pint.txt).13newcampaign tests,defaultHTTPfakeonly. [Earlier isolatedtestrestore race](test_restore_race.txt) corrected and sequentialfullrerungreen.
- [Historical530file preservation](historical_integrity.json), [changed9PHP/testfiles](changed_files.json). No migration/table/parsertechnology changes; README/PLAN/TASKS updated.

Read-only/inspection:

```bash
php artisan documents:pilot status
php artisan documents:sync lex --report=29
php artisan documents:sync lex --items=29 --status=review
php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run
```

For an **open** authorized campaign only, originalprogress/budget and one-worker checks apply:

```bash
SCRAPING_LIVE_ENABLED=true php docs/p5/pilot.php execute
```

Currentcampaign is closed; this command refuses it before network access. Noreset/archive/resume-stopped command was added. Newtraffic requires the separately reviewed nextstep; never clear the budgetcache to bypass stop.

Recreate reports offline (no source requests, no DB writes):

```bash
python3 docs/p5/finalize.py
python3 docs/p5/report.py
```

`audit.php`, `pilot.php audit`, `storage_audit.php` use fixeddevelopment read-only snapshots. Do not rerun historicalP0–P4drivers or overwrite their evidence. TestDB restoration is sequential AFTER fullsuite exits; never overlap its migrationlifecycle.

Final read-only checks:[environment/testschema](final_checks.json),[originalrowquality/timestamp preservation](preservation.json),[cross-artifact verification](verification.json). Nooriginalrow hash changed under the baseline audit convention. TemporaryPDF/OCR files0;defaultlive/schedulerfalse;dev/testDBnames verified.
