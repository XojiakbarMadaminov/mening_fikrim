# P5.1 — Pilot Completion, Discovery & Language Availability

2026-10-29, Asia/Tashkent. Laravel13 / PHP8.5 / PostgreSQL17.11. Result: **CONDITIONAL GO** for a separately authorized P6; no P6,1000/full crawl,scheduling or deployment was started.

## 1. Executive result and baseline

P5 NEW canonical:**158**. P5.1 additional NEW canonical:**42**. Combined:**200/200**. Final database:**229 documents /625 identifiers /541 contents**. Quality:**449 verified /92 needs_review**. These are stored-content quality states, not human legal certification.

Starting state matched P5 exactly:187 documents,509 identifiers,433 contents,344verified/89review,29runs/532items,24 historical failed jobs,0jobs,9 migrations. No database baseline discrepancy. The original preflight JSON page_budget=null was a diagnostic-key typo; unchanged config and final_checks prove the actual page cap8 ([Stage A correction](p5_1/stage_a_results.json)). [Baseline](p5_1/database_baseline.json),[preflight](p5_1/stage_a_preflight.json). Queue database,retry_after960s,job timeout900s/lease930s unchanged;live/scheduler defaultsfalse,oneworker,2000ms,400per-context request cap. No database reset/truncate/drop or new database.

## 2. Initial flow and root causes

Discovery → shared paced download → existing HTML/PDF/OCR extraction → strict identity/status + per-content quality → universal DocumentImporter → PostgreSQL + atomic run-item outcome. P4 already persisted usable review text. P5.1 preserves that behavior.

The old implementation did **not** universally require every supported language: it already skipped metadata_only nodes. Its shortcomings were binary full_text/metadata_only evidence with no explicit unknown/absence model, lost raw language-label context in successful imports, and no separate document coverage status. Missing required-language failure and quality uncertainty could be difficult to distinguish in aggregate diagnostics. Ruscha/Русча were genuinely rejected by the language normalizer. P5's coverage driver capped the1429-record partition at8pages/160IDs even though inclusive splitting primitives existed.

## 3. Availability implementation and schema

**No migration, new column, business table or database.** Existing document metadata JSONB stores `language_availability[BCP47]`:status,evidence_type,evidence,source_id/source_url,checked_at and at most one previous observation. Dictionary keys prevent duplicate language observations. Old untouched documents are not silently backfilled with invented evidence.

- available:source card/verified identity representation, including a confirmed representation whose extraction fails.
- not_available:short explicit document-body translation notice; sourceURL,raw card-language label,notice excerpt and source HTML SHA are retained.
- unknown:no conclusive source evidence, including missing document controls. Four tracked default languages are uz-Cyrl,uz-Latn,ru,en; a missing link is never converted to absence.

The PDF global navigation menu remains ignored. HTTP200 alone is not evidence; contradictory current-source language controls vs card language raise a visible source-language-fallback review, preserving identity safety. Physical PDF language diagnostics are retained. This detects a specific observable fallback pattern; arbitrary semantically wrong fallback with matching metadata is **not proven detectable**.

Content records remain verified/needs_review only. No fake quality or empty rows for absent/unknown translations. Confirmed extraction failures remain in durable language/run diagnostics; explicit availability_status and extraction_outcome labels are included for subsequent attempts (offline regression-tested after the pilot), while this pilot's classifications are proven by the persisted observation maps and original run outcomes; if no usable text exists, no new document/content is fabricated. No extra probes are made for unoffered unknown translations. Explicit source re-import can recheck availability; absence is not cached forever. Source-changing evidence retains the preceding observation and never deletes old trusted text.

## 4. Quality and coverage aggregation

Document quality: no usable content→extraction_failed; any stored review/protected candidate or confirmed available language missing/failed extraction→needs_review; otherwise all stored representations verified→verified. Confirmed absent or merely unknown translations alone do not cause quality review.

Separate `language_coverage_status`:no_usable_content,incomplete (confirmed available missing/failed),unknown (unresolved availability),complete (all tracked availability known and all available variants stored). Thus verified content can coexist with unknown language coverage. Legacy rows retain their required-language contract until a fresh observation; their coverage stays unknown. Content validator thresholds,PDF/OCR/table/image/order checks and original reason codes were not weakened.

Verified text protection,latest pending candidate,shrink/stale guards,canonical anchors and UNIQUE(document_id,language) are unchanged. Reprocessing failure preserves verified text while exposing incomplete current extraction coverage. Checked-at/source-hash refresh alone does not inflate business updates; JSONB object-key order is compared semantically with strict scalar types.

## 5. Language normalization and evidence

Ruscha and Русча now map unambiguously to ru alongside Русский. Original labels remain in availability evidence and parsed-card diagnostics. Unknown/ambiguous Uzbek script labels still fail closed. [Actual P5 label errors](p5_1/language_label_evidence.json) are retained diagnostic evidence, not fabricated raw HTML. Fresh re-imports of1597458,8119972,5210585 associated with their existing canonical45,148,159. Fresh cards in this invocation used Русский; the localized P5 labels were tested from saved actual diagnostic values.

## 6. Bounded discovery and resume

Campaign-specific durable database-cache checkpoints store node query,depth,committed page/prefix,total,unique source rows,children and completeness. Raw Viewstate/cookies remain transient. The service is outside the source adapter; adapters still do no DB writes.

Limits:8pages/partition,depth10,63partitions,finite campaign HTTP budget. Large page1 listing total vs page capacity triggers inclusive date splitting; children fully enumerate through the existing ASP.NET paginator. Inclusive midpoint/end+1 avoids lost or overlapping calendar days. Parent count and prefix IDs must reconcile with the unique child union. Single-day overflow,depth/partition cap and count mismatches remain explicitly incomplete. Interrupted pages replay with source total/prefix checks; completed nodes are not refetched. Budget/access errors propagate without clearing checkpoints.

Actual oliy,statusY,2020-01-01..2020-12-31:listing **1429**, unique discovered **1429**. **29 reconciled complete nodes (15 leaves),0 unresolved incomplete nodes**. Initial parent page-cap overflow is retained in each node's enumeration evidence, then resolved by children; it is not erased or claimed initially complete. Cached repeat0requests; selected complete leaf fresh replay stable. Live discovery was not hard-killed; interrupted-prefix/drift/budget recovery is exercised in offline real-PG regressions. Global,undated,other-date/status coverage is not certified.

|Leaf from|To|Listing total|Unique IDs|Pages|Complete|
|---|---|---:|---:|---:|---|
|01.01.2020|15.02.2020|60|60|3|True|
|16.02.2020|09.03.2020|135|135|7|True|
|10.03.2020|01.04.2020|114|114|6|True|
|02.04.2020|17.05.2020|96|96|5|True|
|18.05.2020|09.06.2020|69|69|4|True|
|10.06.2020|01.07.2020|99|99|5|True|
|02.07.2020|16.08.2020|115|115|6|True|
|17.08.2020|08.09.2020|90|90|5|True|
|09.09.2020|01.10.2020|78|78|4|True|
|02.10.2020|24.10.2020|75|75|4|True|
|25.10.2020|16.11.2020|114|114|6|True|
|17.11.2020|28.11.2020|93|93|5|True|
|29.11.2020|09.12.2020|102|102|6|True|
|10.12.2020|20.12.2020|141|141|8|True|
|21.12.2020|31.12.2020|48|48|3|True|

Only 269 discovered IDs currently map to 93 proven stored groups. The remaining source IDs are not assigned guessed canonical identities.

[Coverage tree](p5_1/discovery_coverage.json),[fresh replay](p5_1/discovery_fresh_replay.json),[cached replay](p5_1/discovery_cached_repeat.json).

## 7. Identity diagnostics: resolved and unresolved

All five previously unassociated source IDs were fetched diagnostically through the shared budget; no rejected candidate was imported.

-1587862,-1587862,1587860:cards are reciprocal, but second adopting body's number is **1-ҚҚ** in Uzbek cards vs **1-КК** in Russian. Raw parsed values prove a source requisite difference; there is no evidence permitting legal-number equivalence. No speculative normalization/merge.

-8354875,-8345596:HTML siblings link to8345596, while that PDF viewer exposes only its self representation and no reciprocal document-language control. Global language navigation is insufficient proof. Existing8345596 identity/text remains untouched; missing/nonreciprocal graph guard remains enforced. These five IDs are not asserted to be five physical documents.

No parser identity normalization defect was proven beyond the language-label defect; identity conflicts remain manual review. [Actual card/link evidence](p5_1/source_identity_evidence.json),[outcomes](p5_1/identity_diagnostics.json),five new source-evidence regressions. Raw HTML/PDF originals are not archived; card arrays,links,response hashes andtimestamps are retained, not cookie/hidden form state.

## 8. PDF/OCR verification

|Source ID|Type|Pages|Language|Stored chars|Quality|Parse s|OCR s|Persistence|
|---|---|---:|---|---:|---|---:|---:|---|
|8345596|pdf|34|uz-Latn|57197|needs_review|6.744|0.000|stored|
|4797522|ocr|7|uz-Cyrl|7316|needs_review|21.025|20.511|stored|

Both real control texts were retained with unchanged hashes. OCR uses existing Tesseract only; uncertain language/order/structure/image/critical-token/confidence reasons remain. This repeats the two P5 physical samples and **does not expand unique PDF/OCR diversity or certify accuracy**. Additional PDF/OCR coverage remains a P6 gap; no extra extraction technology was introduced. New collection format/language counts:{"html": 108} /{"uz-Cyrl": 39, "uz-Latn": 39, "ru": 30}.

## 9. Separate campaign, staged collection and accounting

ID:`p5-1-20261029`,new canonical target42,stages20→42,180selected-entry-ID cap,1800HTTP cap. Planning:42×(360/158)≈96selections +24controls +60headroom=180. HTTP allocation before live:350discovery+1080collection+120targeted/repeat+250retry/drift reserve=1800; assumptions,not guaranteed costs. Selected IDs:**114/180**,HTTP:**414/1800**. Discovery IDs are not selection IDs or canonical groups.

Explicit followup command changes only the active campaign pointer; old P5 cache state and all29runs/532items remain byte-hash-equivalent. It cannot reopen the predecessor or bypass an access-denial stop. Old receipts cannot execute under the new campaign. Stage advance requires exact preceding new-canonical count,empty queue and a passing controller preservation audit. Normal bounded runs still cap30 and400requests; no concurrency or pacing changes.

|Stage|Cumulative NEW|DB docs|Contents|Verified|Review|HTTP cumulative|Selected IDs|Integrity errors|
|---|---:|---:|---:|---:|---:|---:|---:|---|
|B|0|187|433|344|89|150|10|[]|
|C|20|207|487|395|92|300|62|[]|
|D|42|229|541|449|92|414|114|[]|

|Run|Kind|Source IDs|Canonical groups in run|NEW docs|Alias skips|HTTP|Technical failures|
|---|---|---:|---:|---:|---:|---:|---:|
|30|language_pdf_ocr_controls|5|5|0|0|24|0|
|31|new_collection|20|7|7|13|42|0|
|32|repeat_control|3|3|0|0|18|0|
|33|new_collection|13|5|5|8|30|0|
|34|new_collection|8|3|3|3|30|0|
|35|new_collection|5|2|2|3|12|0|
|36|new_collection|3|1|1|2|6|0|
|37|new_collection|2|1|1|1|6|0|
|38|new_collection|1|1|1|0|6|0|
|39|new_collection|20|8|8|12|42|0|
|40|new_collection|14|7|7|7|30|0|
|41|new_collection|7|2|2|4|14|0|
|42|new_collection|5|2|2|3|10|0|
|43|new_collection|3|1|1|2|6|0|
|44|new_collection|2|1|1|1|6|0|
|45|new_collection|1|1|1|0|6|0|

Usable identity-admitted representation attempts:291;stored:291;persistence success:100.00%;automated quality verification:94.85%. These attempt rates include controls/repeats and exclude diagnostic-only identity rejections. New unique content verification105/108=97.22%;these are different denominators. New contents:**108**,new quality:{"verified": 105, "needs_review": 3};source aliases skipped:59. Technical failures:0;new failed jobs:0;failed extraction representations:0. Quality review stays non-blocking; a completed_with_errors bounded run is not global completion. Stop:{"reason": "approved_target_reached", "at": "2026-10-29T05:37:33+05:00"}.

## 10. Availability evidence and representative cases

Among P5.1 persisted observation maps:confirmed unavailable **0**,unknown **70**,available **118**,available but failed **0**. **182 legacy documents** have no new observation map and are reported separately; they are not inferred absent or included as fresh tested evidence. Unknown tracked translations have no fake content quality.

[Representative examples](p5_1/representative_examples.json) identifies real single-language,absent,unknown and mixed-quality observations when present. Confirmed absence also has an actual retained P0 Russian notice (8507863), tested offline with a clearly reconstructed DOM wrapper; it is not counted as fresh P5.1 live absence. Available-but-failed remains explicitly synthetic regression coverage; no nonexistent live failure is fabricated. [All observations](p5_1/language_availability.json),[quality](p5_1/quality_statistics.json).

## 11. Performance and HTTP safety

End-to-end controller:**882.156s**;active worker:**590.811s**;operator pauses:0. Shared pacing wait:741.902s,request/response:33.146s. These occur within wall time,not additive totals. Discovery/diagnostic work outside queue is in controller wall. New canonical/h:wall171.398,worker255.920. Requests/newcanonical incl broadcoverage/controls:9.857.

HTTP status distribution:{"200": 414};reserved/logged:414/414,minimum start gap **2.000140510s**,retryrequests0. No budget exceeded; no403/429/repeated source errors observed. This does not prove unlimited source permission. Queue retries/leases/fencing remain tested offline; live controlled worker restart preserved outstanding receipt IDs/payloads and finished them. [Restart](p5_1/interruption_resume.json),[repeat](p5_1/repeat_import.json).

HTML parse:{"observations": 141, "sum": 1.007949, "p50": 0.004475, "p95": 0.010592};PDF parse:{"observations": 2, "sum": 27.769211, "p50": 6.744463, "p95": 21.024748};OCR:{"observations": 1, "sum": 20.510736, "p50": 20.510736, "p95": 20.510736}. Worker DB query total:7.463s;transaction+reconciliation estimate:1.001s (not pure SQL-write time). PHP peak:102.5MiB;self/child peak RSS:212.8/193.3MiB,individual high-water values,not summed simultaneous host RAM. [Performance](p5_1/performance.json),[HTTP](p5_1/http_statistics.json),[storage](p5_1/storage_statistics.json).

P5's earlier large-scale estimates remain historical; P5.1's unusually broad1429-ID coverage overhead for42newdocs is not a representative full-corpus throughput benchmark. No new1000/full completion time guarantee is asserted.

## 12. Integrity and preservation

Before187/509/433;after229/625/541. Existing187canonical anchors,509identifier associations,433content IDs/languages/hashes/quality remain stable. Existing24failed-job hashes and stopped P5 campaign/ledger hashes are unchanged. Legitimate control re-import refreshed provenance/availability/fetch-check metadata; it did not alter stored text or downgrade verified quality. Duplicate language/alias,orphan,hash/empty-text checks pass;no deletion or fake translation. Old evidence files changed:0. [Integrity](p5_1/integrity_audit.json),[original issues/status/hash preservation](p5_1/preservation.json),[historical files](p5_1/historical_integrity.json),[final DB snapshot](p5_1/database_final.json).

## 13. Tests, migration compatibility and changed files

Baseline143tests/619assertionsPASS. Final **172 tests/719 assertions PASS**,errors/failures/skipped0;PintPASS. Existing P0/P0.1 offline:16/37PASS. Default tests never contact Lex.uz; PostgreSQL destructive test lifecycle is confined to mening_fikrim_active_test. Nine existing migrations still Ran; no schema changes; isolated test schema restored sequentially after tests. [Validation](p5_1/validation_summary.json),[JUnit](p5_1/final_junit.xml),[Pint](p5_1/final_pint.txt).

Initial new test helper collided with Laravel's query() method (renamed); six old idempotency assertions then exposed new observation timestamp/JSONB ordering changes (fixed without weakening assertions). These failures are preserved in p5_1 logs. A new receipt-fencing test initially created the predecessor ledger before creating its campaign, correctly triggering the no-reset guard; fixture order was fixed. A report-export helper import was moved before bootstrap after a formatter exposed the ordering error; application data was unaffected. New real-source fixtures verify legal-number conflict,missing/nonreciprocal links and actual P5 raw labels. Existing parser,quality/persistence,concurrency,queue retry/lease/recovery and protection regressions pass. [Changed files](p5_1/changed_files.json). No new OCR/PDF framework or source identity equivalence rule.

## 14. Commands and remaining risks

Executed creation (do not rerun/reset):

```bash
php artisan documents:pilot followup --after=p5-20261028 --id=p5-1-20261029 --requests=1800 --sources=180
SCRAPING_LIVE_ENABLED=true php docs/p5_1/pilot.php execute
```

Read-only/current checks and offline quality recheck:

```bash
php artisan documents:pilot status
php artisan documents:sync lex --report=45
php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run
php artisan documents:quality-recheck lex --status=needs_review --limit=20
vendor/bin/phpunit
vendor/bin/pint --test
```

The execute command resumes only an open matching P5.1 campaign after checking stored checkpoints/receipts/integrity. This campaign is now terminal stopped;execute refuses more traffic. No command resets it for additional collection. P6 needs separate explicit authorization and new finite scope/budgets.

Remaining:global/source access permission and undated/other partitions unverified;JSONB/cache coordination is bounded pilot state,not a long-term archival registry;cacheclear/eviction can lose resumable state and fails closed;single-controller operation is required. Raw originals/full version history are absent. Fallback detection is structural,not semantic language certification. Five source identity IDs remain unresolved. OCR/PDF unique diversity/manual accuracy and long-duration storage/worker reliability remain open. No verified promotion of uncertain content.

## 15. P6 recommendation

**CONDITIONAL GO**:the original200NEWcanonical pilot is complete with non-blocking quality persistence and measured bounded coverage/integrity. A separately authorized next pilot should address source access conditions,additional unique PDF/OCR/manual QA,raw-source retention decisions and long-running durability; unresolved identity records stay excluded. No automatic1000/full import,scheduler or deployment.
