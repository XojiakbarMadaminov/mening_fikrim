# P6 HTTP performance optimization

This continues the interrupted P6 implementation. Historical P0–P5.1 evidence is unchanged. Live collection results must be read separately from the deterministic offline comparison below.

## Recorded baseline

`historical_http_audit.json` reclassifies the existing logs without contacting Lex.uz. P5 made1,229requests for158new canonicals (7.778/requestpernew):55discovery/pagination,585document/languageHTML,585identitycards,4PDFdownloads.305GET observations repeated the sameURLandSHA. Repetition alone does not establish that a request was unnecessary:explicit repeat controls and fresh identity/legal metadata may require it. P5.1 made414requests/42new (9.857),including94discovery/pagination.52identicalURL/SHA repeats. Historical cache hits and exact alias/control attribution were not instrumented and are not invented.

## Changes

- `LexHttpClient`:exact-URL GET response memoization inside one `LexUzScraper::fetch` only, bounded24responses/64MiB, reset on context/session/fetch end. No cross-document freshness assumption. Every real retry/redirect/discovery/card/PDF request still reserves a durable token before sending and uses the shared>=2000ms pacing.
- `RunItemTracker` and `ScrapeRunManager`:explicit campaign `collect_once` policy permits an already resolved same-run alias to reuse a prior persisted needs-review result. Identity must be verified, every required representation usable, no technical retry pending, listing/legal evidence compatible. Quality issues and incomplete quality state are retained. Normal strict recheck behavior remains available.
- `BoundedDiscovery`:completed PostgreSQL checkpoints are reused after cache loss. Interrupted pagination safely replays its bounded prefix in a new session; cookies/ViewState are not retained. Drift is rejected.
- `docs/p6/pilot.php`:known identifiers and previously selected IDs are excluded from new-document selection. This is an explicit bounded collection policy, not a claim that an old URL remains fresh forever. A separate repeat control still downloads fresh sources.
- `documents:reprocess`:complete private archived source graphs can reuse existing extraction/import rules with zero HTTP and the original observation time. Missing/disabled archives fail closed. Historical229documents are not redownloaded for archive backfill.
- HTTP logs now include purpose, entry source, context, campaign, attempt, SHA, bytes and timing; run items contain request/cache-hit metrics. No cookies, credentials or hidden form values are logged.

## Direct before/after measurement

`P6HttpOptimizationTest` replays the same reconstructed saved-source graph, three alias entrypoints, four source nodes and three usable language texts. The Russian-labelled notice is metadata only; stored fixture texts are uz-Latn, uz-Cyrl and en. All content contains a retained image warning. This is a synthetic offline regression fixture, not live source traffic.

| Metric | Strict recheck baseline | Collect-once optimization |
|---|---:|---:|
| Simulated HTTP requests |24|8|
| New canonical documents |1|1|
| Requests/new canonical |24|8|
| Stored language texts |3|3|
| Needs-review texts |3|3|
| Skipped confirmed aliases |0|2|
| Failed jobs |0|0|
| Virtual pacing elapsed seconds |46|14|

Measured request reduction:66.67%. Canonical anchor, identifiers, legal status, content hashes, languages and quality issues are identical. `http_benchmark.json` contains actual local test durations; those are test execution times, not live documents/hour. Virtual pacing throughput must not be presented as measured network throughput. The fixture isolates avoidable alias repetition and is not representative of the full corpus format mix.

## Correctness costs and remaining limits

A fresh three-language graph ordinarily needs3HTML+3independentcardGETs, before discovery/PDF overhead. The approximate<=5request target is not guaranteed. Cards cannot be skipped merely because titles or URLs resemble one another. Unknown language availability remains unknown; no guessed translation URLs are fetched. Parsed metadata does not prove a missing translation absent.

No local extraction parallelization was introduced:the available measurements identify HTTP pacing as the main cost, and concurrent PDF/OCR extraction adds memory/lease complexity without measured benefit. Parser/OCR technologies and quality validation remain unchanged. Raw retention is disabled pending source permission; real offline archive coverage therefore remains a separately reported limitation even though regression fixtures verify the implementation.

Latest complete regression: 201 tests / 860 assertions PASS; Pint PASS. The original baseline was 172 / 719; 196 / 836 passed before policy traffic and 200 / 853 before main collection. The latest count includes the controlled source-label restart fixes. Durable budget/cache-loss/SIGKILL/concurrent-controller/fencing/pause/checkpoint, private archive/hash/quota/disk-write/orphan, offline HTML/PDF replay, idempotency and verified protection are covered. Final live cost, throughput and gaps will be recorded in P6_REPORT.md and performance.json; no unexecuted improvement is claimed.

A real validation response exposed the unambiguous English source label `Инглизча`. The normalization map now recognizes this label as `en`; the raw label and all fallback/identity/unknown-language guards remain intact. `tests/Fixtures/p6_english_label.json` contains parsed real source evidence, and a regression test covers it. This changes only language-label normalization, not extraction engines or quality rules. A controlled pause/restart retained113request tokens; an explicit provenance correction records the old process parser version for14diagnostic observations. No canonical or content rows changed.

## Actual terminal pilot measurements

The fixed discovery scope was exhausted at 850 NEW canonical documents; the campaign stopped without expanding scope or resetting its budget. Main traffic: 6,003 requests, **7.062 per new canonical**. Including the separate diagnostic validation: 6,196 requests, 7.289 per new canonical. The approximate five-request target was **not reached**. Main active controller time was 3h30m13s, about 242.62 new canonicals/hour. End-to-end wall time was 3h35m49s; the estimated operator/process gap was 5m36s. These timings overlap with worker/network/pacing measurements and must not be added together.

All P6 request purposes: 4 source-policy, 1,029 entry-document, 2,988 canonical identity cards, 1,960 language-variant, 23 PDF-download, 7 PDF-viewer, 50 discovery and 135 pagination requests. There were 35 same-flow memoization hits and 636 repeated identical GET URL/SHA observations across separate observations. Those 636 are not all safely avoidable: freshness and independent identity/control checks still apply. No inline retry, HTTP403/429, pacing violation or new technical failed job occurred. One policy redirect led to a robots404 response; all 6,194 other HTTP responses were200. See http_statistics.json for exact classification.

The current pilot's request ratio is numerically below P5's 7.778, but source/date/format distributions differ. This is **not** a controlled live causal improvement measurement. The defensible direct comparison remains the offline 24→8 alias fixture with identical canonical/text/language/quality results. Nine new persisted PDF/OCR representations all remain needs_review; 2,022 new contents passed existing automated rules and 200 retained review diagnostics. No checks were removed to improve the ratio.
