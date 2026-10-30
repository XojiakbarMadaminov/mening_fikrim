# P6 — Durable Corpus Collection and HTTP Efficiency

**Status: STOPPED; 850 / 1000 NEW canonicals; bounded_discovery_scope_exhausted.**

<!-- P6_RESULTS_BEGIN -->
Actual terminal results; generated 2026-10-29T15:50:01+05:00.

| Metric | Actual result |
|---|---|
| P6 NEW canonical documents | 850 / 1000 |
| Starting documents / contents | 229 / 541 |
| Total documents / identifiers / contents | 1079 / 3104 / 2763 |
| Verified / needs_review contents | 2471 / 292 |
| New content representations | 2222 |
| All P6 HTTP attempts / reserved tokens | 6196 / 6196 |
| Validation / main HTTP attempts | 193 / 6003 |
| Main selected source IDs / maximum | 1183 / 3300 |
| Minimum actual request-start interval | 2.019004 seconds |
| Inline HTTP retries / new failed queue jobs | 0 / 0 |
| HTTP non-2xx status observations | {"302":1,"404":1} |
| Technical failed import items | 0 |
| No-usable-body import outcomes / retained guard review items | 2 / 144 |
| Main active controller duration | 3h 30m 13s |
| Main end-to-end wall / operator or noncontroller gap | 3h 35m 49s / 0h 05m 36s |
| Validation active duration (including policy) | 0h 18m 44s |
| Available language observations | 2340 |
| Confirmed unavailable / unknown language observations | 46 / 1202 |
| Available but failed/missing / legacy unassessed documents | 0 / 182 |
| Complete / incomplete discovery leaves | 31 / 0 |
| Discovered unique source IDs | 3007 |
| Distinct text PDF / OCR PDF samples | 11 / 10 |
| Archived blobs / bytes | 0 / 0 |
| Database physical growth / bytes per NEW canonical | 51625984 / 60,736.45 |
| Historical failed jobs retained | 24 |
| Integrity / budget / pacing gates | PASS / PASS / PASS |
| Tests / assertions / failures or errors / Pint | 201 / 860 / 0 / passed |
| Human QA preparation | 30 distinct documents; review pending |
| Campaign status / reason | stopped / bounded_discovery_scope_exhausted |
| Recommendation | NO-GO |

### Executed stages

| NEW canonical gate | Documents | Contents | Verified | Review | HTTP reserved | Integrity |
|---:|---:|---:|---:|---:|---:|---|
| 30 | 259 | 620 | 509 | 111 | 344 | PASS |
| 100 | 329 | 808 | 665 | 143 | 827 | PASS |
| 300 | 529 | 1356 | 1171 | 185 | 2361 | PASS |
| 600 | 829 | 2102 | 1845 | 257 | 4244 | PASS |
| 1000 | Not reached | — | — | — | — | Not claimed |

### Actual performance and projections

Main requests per NEW canonical: 7.062; all-P6 overhead included: 7.289. Main active throughput: 242.62 new documents/hour. These are real pilot measurements, not the offline alias benchmark. Different source/date/format mixes prevent a direct causal speed comparison with P5.

| Planning target | Same-mix hours | Same-mix HTTP |
|---:|---:|---:|
| 1000 | 4.12 | 7063 |
| 10000 | 41.22 | 70624 |
| 100000 | 412.18 | 706236 |

Planning estimates only. OCR-heavy or failed-scan workloads can be much slower; discovery overhead, retries, source aliases and future access/retention conditions can change. PDF parse timings include OCR. See performance.json for pacing/HTTP/query/queue/memory details and uncertainty.

### Storage measurements

Database before collection / final: 41334451 / 92960435 bytes; final free disk: 120440827904 bytes. Physical database growth includes diagnostic history and allocation effects. Earlier stage database/archive/free-disk observations are retained; per-relation sizes were measured only at final reporting, so no historical per-table growth is claimed.

- 10000 documents: approximately 579.23 MiB incremental database growth at the observed mix.
- 100000 documents: approximately 5,792.28 MiB incremental database growth at the observed mix.

These are planning estimates. Live archive bytes stayed zero because retention was disabled; future original-file storage cannot be estimated from this zero-retention pilot. See [storage_statistics.json](p6/storage_statistics.json) for relation sizes and limitations.

### Bounded discovery coverage

| Root | Inclusive dates | Listing count | Unique source IDs | Complete |
|---|---|---:|---:|---|
| nat | 01.01.2020 to 31.01.2020 | 279 | 279 | yes |
| loc | 01.01.2020 to 31.01.2020 | 93 | 93 | yes |
| int | 01.01.2020 to 31.12.2020 | 7 | 7 | yes |
| tech | 01.01.2026 to 29.10.2026 | 105 | 105 | yes |
| court | 01.01.2020 to 31.12.2020 | 11 | 11 | yes |
| oliy | 01.01.2010 to 31.12.2010 | 351 | 351 | yes |
| court | 01.01.2010 to 31.12.2010 | 252 | 252 | yes |
| loc | 01.02.2020 to 29.02.2020 | 139 | 139 | yes |
| int | 01.01.2010 to 31.12.2010 | 93 | 93 | yes |
| tech | 01.01.2020 to 31.12.2020 | 32 | 32 | yes |
| nat | 01.02.2020 to 29.02.2020 | 258 | 258 | yes |
| oliy | 01.01.2020 to 31.12.2020 | 1429 | 1429 | yes |

All 12 fixed queries were reconciled. Cross-partition/category overlap is deduplicated: the total unique source count is not the sum of listings and is not a canonical count. The fixed scope had no additional eligible selections after known identifiers and selected sources were excluded. No scope expansion, budget reset or terminal resume was attempted. Global coverage is not established.

### Evidence and remaining gaps

Raw retention stayed disabled: zero live original files is an explicit permission/coverage limitation. Offline HTML/PDF replay was verified using labelled regression fixtures; the real archive dry-run selected zero graphs. PDF/OCR sampling goals were 15 text / 5 OCR; actual counts above are not accuracy certification. Human review has not been performed. Unknown availability remains uncertainty, not confirmed absence. Global Lex.uz coverage, inactive/undated/revision completeness and production permission are not established.

The two no-usable-body import outcomes were metadata-only entry graphs (4774598 inconclusive; 6576817 explicit translation notice). Existing whole-import diagnostics call these extraction_failed because no usable body was obtained. Their language outcomes remain not_attempted; no confirmed-available translation extraction failure, fake content or failed queue job is inferred. Guard review receipts also include earlier label failures subsequently resolved by bounded re-import; they are not a count of unique unresolved documents. See [review_diagnostics.json](p6/review_diagnostics.json).

Machine evidence: [final_summary.json](p6/final_summary.json), [stage statistics including aliases/existing/new/quality/HTTP/active time](p6/stage_statistics.json), [integrity](p6/final_integrity.json), [discovery](p6/discovery_coverage.json), [quality](p6/quality_statistics.json), [language](p6/language_statistics.json), [extraction](p6/extraction_statistics.json), [performance](p6/performance.json), [human QA](p6/human_qa_manifest.json). No larger import or scheduler was started.

<!-- P6_RESULTS_END -->

## Initial problem and implementation

P5/P5.1 run/item receipts were already durable, but the campaign budget, selected IDs, stop latch and discovery trees depended on database cache. Clearing cache could lose campaign authority. Original source bodies were not retained, and quality-review aliases could repeat an entire source graph unnecessarily.

The universal pipeline remains discovery → paced HTTP → existing HTML/PDF/Tesseract extraction → identity/legal/language validation → existing quality rules → DocumentImporter → PostgreSQL and durable run-item outcomes. Source adapters perform no database writes. Usable needs-review text remains storable; verified content, canonical IDs and per-language uniqueness retain their P4/P5.1 protections.

Two additive migrations were applied non-destructively:

- `2026_10_29_000008_create_scrape_campaigns.php`: `scrape_campaigns` and `scrape_campaign_checkpoints`.
- `2026_10_29_000010_create_source_archives.php`: `source_archive_blobs` and `source_archive_observations`.

Separate immutable blobs and observations avoid duplicate physical files while preserving alias/fetch provenance. Existing six business tables, queue/cache tables and historical data remain intact. P6 explicitly authorized these minimal additional structures.

Principal implementation files: `DurableCampaign`, `PilotCampaign`, `BoundedDiscovery`, `SourceArchive`, `ArchiveCapture`, `ArchivedSourceReprocessor`, `ArchivedLexHttpClient`, `LexHttpClient`, `RunItemTracker`, `ScrapeRunManager`, queue jobs, `ManageCampaign`, `ReprocessArchivedDocuments`, configuration and service-provider bindings. New regression coverage is in `DurableCampaignTest`, `SourceArchiveTest`, `P6HttpOptimizationTest`, source-label fixtures and the isolated crash helper. The interrupted implementation already contained some of these classes; this continuation integrated and completed them rather than rebuilding the parser.

## Durability, ownership and recovery

New campaigns use PostgreSQL authority through the existing PilotCampaign facade. One global PostgreSQL session advisory lock, a UUID owner token, generation and backend PID fence exclude competing controllers and stale writers. A long OCR job does not outlive a short controller lease: ownership lasts for the database session. New workers must own the same campaign; a standalone worker cannot consume another campaign's receipts.

HTTP tokens are reserved atomically before sending, including retries, redirects, discovery, cards and PDFs. A crash can burn an unobserved token; it never refunds one. Permanent context budgets retain the existing 400-request cap. Persisted request-start timestamps preserve pacing after cache loss. An unfinished HTTP reservation also requires a conservative timeout recovery window. All real traffic remains one worker with at least 2,000 ms between starts.

Running → pausing → paused → running is recoverable. Completed/stopped campaigns cannot ordinarily resume, and a new campaign cannot bypass a terminal safety stop. SIGKILL/cache-loss, competing sessions, stale fences, pause/resume and budget exhaustion are regression-tested. Reserved queue receipts wait for database `retry_after`; recovery does not immediately poll an old live lease as ready. Existing run-item dispatch tokens, leases, retries and idempotency are retained.

Discovery persists query/date/depth, page fingerprints and committed prefixes, source IDs, parent/child relationships and completeness. Restart replays only the necessary bounded prefix in a fresh ASP.NET session. Cookies and ViewState are not persisted. Drift or unresolved enumeration is never silently called complete. A completed PostgreSQL checkpoint is reused without HTTP.

## Private archive and offline reprocessing

The configurable `legal_sources` local disk is private and not served. SHA-addressed HTML/PDF paths, format/magic/EOF checks, file/capture limits, a storage quota, atomic sibling-file rename and hash verification protect writes. Identical bytes reuse a blob. Traversal, unsafe URLs, public/symlink roots and HTML containing unretained private form/session state are refused. Sources are preserved as original bytes or rejected; sanitized/reconstructed HTML is not called an original live source.

Archive writes happen outside the source adapter and after the content transaction. Failure never deletes imported or verified content. Quota/write/observation failures stop the campaign with diagnostics. A crash or DB failure after rename leaves a detectable orphan. Audit reports missing/corrupt/orphan files without deleting them.

**Live raw retention is disabled.** The current source terms leave bulk/raw retention permission unresolved. This is the explicitly allowed disabled mode, not successful live archive coverage. Source URL, SHA, byte size, observation time, parsed source evidence and disabled reason remain available. Historical documents are not redownloaded for archive backfill.

`documents:reprocess` selects complete archived graphs, reuses the existing parser/importer, validates identity/language/quality, preserves the original observation time and supports dry-run and limits 1–30. Missing/corrupt/incomplete archives fail closed without network or content deletion. Regression fixtures verify original-byte HTML and generated PDF replay, repeated imports and verified-content candidate protection. The development dry-run selected zero real archived documents because retention is disabled; this coverage gap is explicit.

## Language and identity

Availability remains available / not_available / unknown, separate from content quality verified / needs_review and legal active / pending / inactive. Missing translations create no empty content rows. Unknown availability is not absence. Confirmed available extraction failure stays incomplete. Review content is not automatically promoted.

Real validation exposed the unambiguous card label `Инглизча`. Its normalization to `en`, plus the observed explicit-script labels `Ўзбекча (к)` / `O‘zbekcha (k)` to `uz-Cyrl`, were added, with parsed actual source evidence in `tests/Fixtures/p6_english_label.json` and `p6_uzbek_script_labels.json`. Unknown labels, language fallback and reciprocal/requisite guards remain. HTML/PDF/OCR engines, ContentQuality, DocumentValidator and DocumentImporter are unchanged; parser preservation hashes identify the language mapping changes.

The five previously unresolved source IDs remain excluded. Two additional historical source groups (`-8547769`, `-8548754`) showed conflicting type requisites during diagnostic validation. They were not merged or imported. Guard-rejected sources remain diagnostics and do not count as newly collected canonicals; persisted content without verified identity would trigger a terminal gate.

A controlled pause at 113 validation requests loaded the normalization fix without resetting progress. Fourteen diagnostic observations were explicitly corrected to the parser hash actually loaded by the old process. Source SHA/times and canonical/content rows were unchanged. Workers must be paused/restarted before changing PHP source.

## HTTP optimization and measurement

See [PERFORMANCE_OPTIMIZATION.md](p6/PERFORMANCE_OPTIMIZATION.md) and machine-readable request audit/benchmark. Historical P5 cost: 1,229 / 158 = 7.778 requests per new canonical. Main avoidable pattern: repeating a persisted review-quality canonical graph through aliases.

The direct offline comparison uses the same reconstructed source graph and alias sample: 24 → 8 simulated requests, a measured 66.67% reduction, with identical canonical anchor, identifiers, text hashes, languages, legal status and quality issues. Three needs-review representations remain needs-review; no failed job is created. Virtual pacing (46 → 14 seconds) and local test time are not real network throughput.

Reuse is bounded to an exact URL inside one fetch, an explicit collect-once same-run alias with verified identity and usable complete persistence, or a completed durable discovery checkpoint. Known identifiers are excluded from new-document selection; explicit repeat controls still fetch fresh sources. There is no permanent URL freshness assumption or skipped metadata/language check. A fresh three-language graph ordinarily requires three HTML and three independent card requests, so the approximate five-request goal cannot be guaranteed. No unmeasured local extraction parallelization was introduced.

## Fixed campaign controls

Validation: `p6-validation-20261029`, target zero new canonicals, at most 2,000 HTTP / 200 selected IDs, diagnostic sample cap 30. Main: `p6-corpus-1000-20261029`, target 1,000 NEW canonicals, at most 13,000 HTTP / 3,300 selected IDs. Combined ceilings are 15,000 / 3,500; unused validation budget is not transferred to increase the main cap.

Main stages are 30 → 100 → 300 → 600 → 1,000. Each run remains 1–30 groups and every context at most 400 requests. Fixed scope: six supported roots over bounded 2020/2010 ranges and a bounded 2026 technical range, declared in `p6/scope.php`. Date subdivision retains page cap 8, depth 10, campaign partition cap 255, discovered-ID cap 20,000 and 12-hour campaign duration. Single response/capture/archive limits are 32 MiB / 256 MiB / 2 GiB. Scheduling and default live access stay disabled.

After each bounded run/stage: audit old IDs, hashes, quality, canonical/source relationships, ledger and failed-job hashes; check duplicates/orphans/empty content, archive integrity, queue state, access errors and exact canonical count. Source IDs, aliases, languages and repeat controls are not target units. HTTP 403/429, repeated network/5xx, exhausted caps, unsafe persistence, unreconciled discovery or integrity failure stop live traffic without bypass.

## Tests and evidence

Initial suite: 172 tests / 719 assertions PASS. Current complete suite: **201 tests / 860 assertions PASS**; Pint PASS. Historical P0/P0.1 offline suites: **16 + 37 PASS**. Default tests prohibit stray Lex.uz requests and use only `mening_fikrim_active_test`. Development remains `mening_fikrim_active`.

New tests cover durable budget/context/cache loss, SIGKILL and fencing, queue recovery timing, page/depth/checkpoint bounds, private archive/dedup/quota/disk write/hash/orphan failures, offline HTML/PDF reprocessing, identity/idempotency/verified protection and measured HTTP alias reuse. Migration tests now enumerate the exact P6-authorized tables and rebuild surviving PostgreSQL functions. No assertions were relaxed to hide parser failures.

Human QA preparation exported 30 distinct canonical documents after collection, with source/content/hash/language/format, excerpts, automated issues, honest missing original-file references and empty reviewer/observation fields. **No human review or OCR accuracy certification is claimed.**

## Remaining limits and next authorization

PDF/OCR sampling goals, live archive coverage/permission, source type conflicts, unknown translation availability, long-running worker/storage growth and narrow discovery scope remain explicit risks. The pilot cannot establish global Lex.uz coverage or legal accuracy. Large-scale projections must use actual collected format/request mix and be labelled planning estimates.

No full crawl, scheduler, production deployment, new OCR/PDF engine, AI, embeddings or frontend work is enabled. Actual results and the final recommendation are recorded above. Terminal safety stops cannot be bypassed through ordinary resume. A larger campaign requires separate authorization.

The implementation and preservation gates passed, but the 1,000-new-canonical acceptance target remains incomplete: **850 collected, 150 remaining**. All 12 predeclared queries were exhausted. The main campaign used 1,183 / 3,300 selected IDs and 6,003 / 13,000 HTTP tokens; unused budgets do not authorize expansion outside its fixed discovery scope. Recommendation: **NO-GO for claiming P6 complete or starting unrestricted collection**. A separately authorized completion campaign needs a new finite category/date scope and budget, preserving this terminal campaign and the 850 documents already collected.

The new cohort contains 2,222 representations: 2,022 verified and 200 needs_review, in uz-Latn (821), uz-Cyrl (842), ru (557) and en (2). There are 2,213 HTML, 3 text-PDF and 6 OCR representations. All nine new PDF/OCR representations are persisted needs_review. Diagnostic validation plus collection produced 11 distinct successful text PDFs and 10 OCR PDFs: the text-PDF goal of 15 remains short by four, while the OCR sampling goal was reached. The 30-document human review and raw-retention permission remain outstanding. None of these results certifies legal or OCR accuracy.

Import-item accounting: 756 imported, 3 unchanged repeat controls, 188 skipped aliases and 238 review outcomes. Of the review outcomes, 94 persisted a new canonical document and 144 retained no-document diagnostics (including the two metadata-only entry graphs). These are execution outcomes, not a count of distinct unresolved documents. Three repeat controls preserved IDs/text hashes/quality; graceful live worker restart and isolated SIGKILL/cache-loss tests passed. Existing 229 canonical records, 625 identifiers, 541 contents, historical ledger, P0–P5.1 evidence and 24 failed jobs passed preservation checks.

## Commands

```bash
php artisan documents:campaign status
php artisan documents:campaign report
php docs/p6/pilot.php audit

# Only for a separately eligible campaign whose status is paused:
php artisan documents:campaign resume --id=PAUSED_CAMPAIGN_ID

# Bounded offline archive replay; currently no live retained graph is available:
php artisan documents:reprocess lex --limit=20 --dry-run
php artisan documents:reprocess lex --limit=1 --document=DOCUMENT_ID
```

`p6-corpus-1000-20261029` is stopped and is not eligible for the resume example. Do not rerun the existing live controller to bypass its fixed scope stop. Status, report, audit and archive dry-run are safe read-only/local operations.
