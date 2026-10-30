# P6 continuation progress

Authorized scope: durable collection, original-source archive support, offline reprocessing, HTTP efficiency and a controlled pilot targeting 1,000 NEW canonical documents. Historical P5/P5.1 campaigns remain terminal. See INITIAL_AUDIT.md, budgets.json and PERFORMANCE_OPTIMIZATION.md.

## Completed

- Baseline recorded: 229 documents, 625 identifiers, 541 contents (449 verified / 92 review), 24 historical failed jobs.
- Integrated PostgreSQL campaign/checkpoint registry, controller session lock and fencing, atomic request budgets, restart pacing and recoverable pause/resume.
- Implemented private bounded SHA-addressed archive, provenance, quota/hash/atomic write checks and offline reprocessing with original observation time.
- Added single-fetch HTTP memoization and explicit collect-once alias optimization. Offline comparison: 24 → 8 simulated requests, identical canonical/text/quality outcomes.
- Added source-backed `Инглизча → en` and explicit Uzbek Cyrillic script-label normalization; raw labels and unknown/fallback/identity guards remain intact.
- Full suite: 201 tests / 860 assertions PASS; Pint PASS; historical offline P0/P0.1 suites: 16 + 37 PASS.
- Applied migrations 000008 and 000010 non-destructively. Historical record/evidence hashes pass repeated audits.

## Live state

Validation campaign `p6-validation-20261029` completed its bounded 30-group sample using 193 HTTP requests. It found 12 distinct successful PDF byte hashes: 8 text-layer and 4 using OCR. The sampling goals of 15 text PDFs / 5 OCR PDFs remain unmet. Two source type conflicts were guarded; two larger scans exceeded the existing 40-page OCR limit and were not falsely reported as successful. Raw retention remains disabled because permission is unclear. Historical database content stayed at 229 documents / 541 representations.

The main campaign `p6-corpus-1000-20261029` is **stopped** at 850 / 1,000 NEW canonical documents with `bounded_discovery_scope_exhausted`. Stages 30, 100, 300 and 600 passed; no stage_1000.json is fabricated. All 12 fixed queries reconciled; 3,007 unique source IDs do not equal canonical documents. Final database: 1,079 documents / 3,104 identifiers / 2,763 contents, 2,471 verified / 292 review. Main requests 6,003 / 13,000; main selections 1,183 / 3,300. No integrity errors, zero queued jobs, no new failed jobs and 24 historical failed jobs retained. PostgreSQL is authoritative; no safety stop was bypassed. Remaining 150 require a separately authorized bounded completion campaign, not reopening this terminal campaign.

A controlled validation pause/restart at 113 requests preserved budget/checkpoints and loaded the source-backed English-label fix. The old process parser hash was explicitly corrected for 14 diagnostic observations; no canonical/content rows changed. The full suite now passes 201 tests / 860 assertions, including reserved-queue recovery timing.

## Operator commands

Read-only:

```bash
php artisan documents:campaign status
php artisan documents:campaign report
php docs/p6/pilot.php audit
```

Both P6 campaigns are terminal. No live controller remains active. Do not run a second worker, legacy P5/P5.1 driver, or the stopped P6 controller to bypass its terminal scope stop.

```bash
# Generic operator example, ONLY when the named campaign is recoverably paused:
php artisan documents:campaign resume --id=PAUSED_CAMPAIGN_ID
php artisan documents:reprocess lex --limit=20 --dry-run
```

Resume only a paused campaign, then invoke its existing controller. A crashed running controller reclaims the same campaign. Tokens are never refunded. Terminal stops cannot resume. Do not create replacement budgets after a safety failure. Pause and restart before changing PHP source; running workers retain already loaded code.

## Terminal results and remaining work

The 1,000-new target is incomplete (150 remaining); NO-GO for claiming full success or starting full collection. Final discovery, language, PDF/OCR, archive, request, performance, storage, stage and integrity evidence and P6_REPORT.md are generated. The human QA manifest contains 30 distinct documents; human review remains pending. Successful distinct PDFs: 11 text-layer / 10 OCR; the 15-text sampling goal remains short by four. Raw files remain unretained by permission policy. No scheduler, unrestricted crawl, new extraction technology or deployment is enabled.

The read-only completion wrapper finished successfully after the controller exited. It generated reports and the QA manifest without Lex.uz requests or restarting collection. Terminal results are in `result_terminal.txt` and P6_REPORT.md. Recoverably paused/running campaigns cannot be finalized as complete. Further read-only regeneration updates observation timestamps and physical database sizes, not campaign counters or historical data.

If the wrapper is interrupted, these commands regenerate actual terminal reports without source traffic:

```bash
php docs/p6/report.php > docs/p6/final_report_terminal.txt
php docs/p6/qa_manifest.php
php docs/p6/finalize_report.php
```
