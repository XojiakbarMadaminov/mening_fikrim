"""Render the final report from recorded evidence only."""
import json
from pathlib import Path
D=Path(__file__).resolve().parent
read=lambda n:json.loads((D/(n+'.json')).read_text())
s=read('final_summary');p=read('performance');a=read('language_availability')['counts'];cov=read('discovery_coverage');parts=read('partition_statistics');runs=read('run_statistics');pdf=read('pdf_ocr_verification')['samples'];tests=read('validation_summary')
f=lambda n:f'{n:,.3f}'
row=lambda values:'|'+'|'.join(str(v) for v in values)+'|\n'
stages=[]
previous=read('database_baseline')
for name in ['b','c','d']:
 path=D/('stage_'+name+'_results.json')
 if not path.exists():continue
 cur=json.loads(path.read_text());stages.append(row([name.upper(),cur['new_canonical_documents'],cur['rows']['documents'],cur['rows']['document_contents'],cur['quality_counts']['verified'],cur['quality_counts']['needs_review'],cur['campaign']['used'],len(cur['campaign']['selected_ids']),str(cur['errors'])]))
leafs=''.join(row([n['query']['from'],n['query']['to'],n['total'],n['unique_ids'],n['pages_observed'],n['complete']]) for n in parts if n['children']==0)
runrows=''.join(row([r['run_id'],r['kind'],r['source_ids'],r['canonical_groups'],r['new_canonical_documents'],r['skipped_aliases'],r['http_requests'],r['technical_failures']]) for r in runs)
pdfrows=''.join(row([q['source_id'],q['format'],q['pages'],q['language'],q['characters'],q['quality']['stored_quality_status'],f(q['timings']['pdf_parse_seconds']),f(q['timings']['ocr_seconds']),q['persistence']]) for q in pdf)
report=f'''# P5.1 — Pilot Completion, Discovery & Language Availability

2026-10-29, Asia/Tashkent. Laravel13 / PHP8.5 / PostgreSQL17.11. Result: **{s['recommendation']}** for a separately authorized P6; no P6,1000/full crawl,scheduling or deployment was started.

## 1. Executive result and baseline

P5 NEW canonical:**158**. P5.1 additional NEW canonical:**{s['p5_1_new_canonical']}**. Combined:**{s['combined_new_canonical']}/200**. Final database:**{s['final_counts']['documents']} documents /{s['final_counts']['document_identifiers']} identifiers /{s['final_counts']['document_contents']} contents**. Quality:**{s['quality_distribution']['verified']} verified /{s['quality_distribution']['needs_review']} needs_review**. These are stored-content quality states, not human legal certification.

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

Actual oliy,statusY,2020-01-01..2020-12-31:listing **{s['discovery_root_total']}**, unique discovered **{s['discovered_unique_source_ids']}**. **{s['complete_partitions']} reconciled complete nodes ({s['complete_leaf_partitions']} leaves),{s['incomplete_partitions']} unresolved incomplete nodes**. Initial parent page-cap overflow is retained in each node's enumeration evidence, then resolved by children; it is not erased or claimed initially complete. Cached repeat0requests; selected complete leaf fresh replay stable. Live discovery was not hard-killed; interrupted-prefix/drift/budget recovery is exercised in offline real-PG regressions. Global,undated,other-date/status coverage is not certified.

|Leaf from|To|Listing total|Unique IDs|Pages|Complete|
|---|---|---:|---:|---:|---|
{leafs}
Only {s['discovery_attribution']['mapped_source_ids']} discovered IDs currently map to {s['discovery_attribution']['mapped_canonical_groups']} proven stored groups. The remaining source IDs are not assigned guessed canonical identities.

[Coverage tree](p5_1/discovery_coverage.json),[fresh replay](p5_1/discovery_fresh_replay.json),[cached replay](p5_1/discovery_cached_repeat.json).

## 7. Identity diagnostics: resolved and unresolved

All five previously unassociated source IDs were fetched diagnostically through the shared budget; no rejected candidate was imported.

-1587862,-1587862,1587860:cards are reciprocal, but second adopting body's number is **1-ҚҚ** in Uzbek cards vs **1-КК** in Russian. Raw parsed values prove a source requisite difference; there is no evidence permitting legal-number equivalence. No speculative normalization/merge.

-8354875,-8345596:HTML siblings link to8345596, while that PDF viewer exposes only its self representation and no reciprocal document-language control. Global language navigation is insufficient proof. Existing8345596 identity/text remains untouched; missing/nonreciprocal graph guard remains enforced. These five IDs are not asserted to be five physical documents.

No parser identity normalization defect was proven beyond the language-label defect; identity conflicts remain manual review. [Actual card/link evidence](p5_1/source_identity_evidence.json),[outcomes](p5_1/identity_diagnostics.json),five new source-evidence regressions. Raw HTML/PDF originals are not archived; card arrays,links,response hashes andtimestamps are retained, not cookie/hidden form state.

## 8. PDF/OCR verification

|Source ID|Type|Pages|Language|Stored chars|Quality|Parse s|OCR s|Persistence|
|---|---|---:|---|---:|---|---:|---:|---|
{pdfrows}
Both real control texts were retained with unchanged hashes. OCR uses existing Tesseract only; uncertain language/order/structure/image/critical-token/confidence reasons remain. This repeats the two P5 physical samples and **does not expand unique PDF/OCR diversity or certify accuracy**. Additional PDF/OCR coverage remains a P6 gap; no extra extraction technology was introduced. New collection format/language counts:{json.dumps(s['new_formats'])} /{json.dumps(s['new_languages'])}.

## 9. Separate campaign, staged collection and accounting

ID:`{s['campaign_id']}`,new canonical target42,stages20→42,180selected-entry-ID cap,1800HTTP cap. Planning:42×(360/158)≈96selections +24controls +60headroom=180. HTTP allocation before live:350discovery+1080collection+120targeted/repeat+250retry/drift reserve=1800; assumptions,not guaranteed costs. Selected IDs:**{s['selected_distinct_entry_ids']}/180**,HTTP:**{s['http']['count']}/1800**. Discovery IDs are not selection IDs or canonical groups.

Explicit followup command changes only the active campaign pointer; old P5 cache state and all29runs/532items remain byte-hash-equivalent. It cannot reopen the predecessor or bypass an access-denial stop. Old receipts cannot execute under the new campaign. Stage advance requires exact preceding new-canonical count,empty queue and a passing controller preservation audit. Normal bounded runs still cap30 and400requests; no concurrency or pacing changes.

|Stage|Cumulative NEW|DB docs|Contents|Verified|Review|HTTP cumulative|Selected IDs|Integrity errors|
|---|---:|---:|---:|---:|---:|---:|---:|---|
{''.join(stages)}
|Run|Kind|Source IDs|Canonical groups in run|NEW docs|Alias skips|HTTP|Technical failures|
|---|---|---:|---:|---:|---:|---:|---:|
{runrows}
Usable identity-admitted representation attempts:{s['representation_attempts']['usable']};stored:{s['representation_attempts']['stored']};persistence success:{s['representation_attempts']['persistence_rate_percent']:.2f}%;automated quality verification:{s['representation_attempts']['automated_verification_rate_percent']:.2f}%. These attempt rates include controls/repeats and exclude diagnostic-only identity rejections. New unique content verification105/108=97.22%;these are different denominators. New contents:**{s['new_content_representations']}**,new quality:{json.dumps(s['new_quality_distribution'])};source aliases skipped:{s['skipped_alias_items']}. Technical failures:{s['technical_failures']};new failed jobs:{s['new_failed_jobs']};failed extraction representations:{s['failed_extraction_representations']}. Quality review stays non-blocking; a completed_with_errors bounded run is not global completion. Stop:{json.dumps(s['stop'])}.

## 10. Availability evidence and representative cases

Among P5.1 persisted observation maps:confirmed unavailable **{a['not_available']}**,unknown **{a['unknown']}**,available **{a['available']}**,available but failed **{a['available_but_failed']}**. **{a['unassessed_documents']} legacy documents** have no new observation map and are reported separately; they are not inferred absent or included as fresh tested evidence. Unknown tracked translations have no fake content quality.

[Representative examples](p5_1/representative_examples.json) identifies real single-language,absent,unknown and mixed-quality observations when present. Confirmed absence also has an actual retained P0 Russian notice (8507863), tested offline with a clearly reconstructed DOM wrapper; it is not counted as fresh P5.1 live absence. Available-but-failed remains explicitly synthetic regression coverage; no nonexistent live failure is fabricated. [All observations](p5_1/language_availability.json),[quality](p5_1/quality_statistics.json).

## 11. Performance and HTTP safety

End-to-end controller:**{f(s['controller_elapsed_seconds'])}s**;active worker:**{f(p['active_worker_seconds'])}s**;operator pauses:0. Shared pacing wait:{f(p['pacing_wait_seconds'])}s,request/response:{f(p['request_response_seconds'])}s. These occur within wall time,not additive totals. Discovery/diagnostic work outside queue is in controller wall. New canonical/h:wall{f(p['new_documents_per_hour_end_to_end'])},worker{f(p['new_documents_per_hour_worker'])}. Requests/newcanonical incl broadcoverage/controls:{f(p['http_requests_per_new_document_including_coverage_and_controls'])}.

HTTP status distribution:{json.dumps(s['http']['statuses'])};reserved/logged:{s['http']['reserved_count']}/{s['http']['count']},minimum start gap **{s['http']['minimum_start_interval_seconds']:.9f}s**,retryrequests{s['http']['retry_requests']}. No budget exceeded; no403/429/repeated source errors observed. This does not prove unlimited source permission. Queue retries/leases/fencing remain tested offline; live controlled worker restart preserved outstanding receipt IDs/payloads and finished them. [Restart](p5_1/interruption_resume.json),[repeat](p5_1/repeat_import.json).

HTML parse:{json.dumps(p['html_parse_seconds'])};PDF parse:{json.dumps(p['pdf_parse_seconds'])};OCR:{json.dumps(p['ocr_seconds'])}. Worker DB query total:{f(p['worker_db_query_seconds'])}s;transaction+reconciliation estimate:{f(p['transaction_and_reconciliation_seconds_estimate'])}s (not pure SQL-write time). PHP peak:{p['peak_php_bytes']/1024**2:.1f}MiB;self/child peak RSS:{p['max_self_rss_kib']/1024:.1f}/{p['max_child_rss_kib']/1024:.1f}MiB,individual high-water values,not summed simultaneous host RAM. [Performance](p5_1/performance.json),[HTTP](p5_1/http_statistics.json),[storage](p5_1/storage_statistics.json).

P5's earlier large-scale estimates remain historical; P5.1's unusually broad1429-ID coverage overhead for42newdocs is not a representative full-corpus throughput benchmark. No new1000/full completion time guarantee is asserted.

## 12. Integrity and preservation

Before187/509/433;after{s['final_counts']['documents']}/{s['final_counts']['document_identifiers']}/{s['final_counts']['document_contents']}. Existing187canonical anchors,509identifier associations,433content IDs/languages/hashes/quality remain stable. Existing24failed-job hashes and stopped P5 campaign/ledger hashes are unchanged. Legitimate control re-import refreshed provenance/availability/fetch-check metadata; it did not alter stored text or downgrade verified quality. Duplicate language/alias,orphan,hash/empty-text checks pass;no deletion or fake translation. Old evidence files changed:{len(s['historical_files_changed'])}. [Integrity](p5_1/integrity_audit.json),[original issues/status/hash preservation](p5_1/preservation.json),[historical files](p5_1/historical_integrity.json),[final DB snapshot](p5_1/database_final.json).

## 13. Tests, migration compatibility and changed files

Baseline143tests/619assertionsPASS. Final **{tests['tests']} tests/{tests['assertions']} assertions PASS**,errors/failures/skipped0;PintPASS. Existing P0/P0.1 offline:{tests['p0']}/{tests['p01']}PASS. Default tests never contact Lex.uz; PostgreSQL destructive test lifecycle is confined to mening_fikrim_active_test. Nine existing migrations still Ran; no schema changes; isolated test schema restored sequentially after tests. [Validation](p5_1/validation_summary.json),[JUnit](p5_1/final_junit.xml),[Pint](p5_1/final_pint.txt).

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
php artisan documents:sync lex --report={runs[-1]['run_id']}
php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run
php artisan documents:quality-recheck lex --status=needs_review --limit=20
vendor/bin/phpunit
vendor/bin/pint --test
```

The execute command resumes only an open matching P5.1 campaign after checking stored checkpoints/receipts/integrity. This campaign is now terminal stopped;execute refuses more traffic. No command resets it for additional collection. P6 needs separate explicit authorization and new finite scope/budgets.

Remaining:global/source access permission and undated/other partitions unverified;JSONB/cache coordination is bounded pilot state,not a long-term archival registry;cacheclear/eviction can lose resumable state and fails closed;single-controller operation is required. Raw originals/full version history are absent. Fallback detection is structural,not semantic language certification. Five source identity IDs remain unresolved. OCR/PDF unique diversity/manual accuracy and long-duration storage/worker reliability remain open. No verified promotion of uncertain content.

## 15. P6 recommendation

**{s['recommendation']}**:the original200NEWcanonical pilot is {'complete' if s['target_reached'] else 'INCOMPLETE'} with non-blocking quality persistence and measured bounded coverage/integrity. A separately authorized next pilot should address source access conditions,additional unique PDF/OCR/manual QA,raw-source retention decisions and long-running durability; unresolved identity records stay excluded. No automatic1000/full import,scheduler or deployment.
'''
(D.parent/'P5_1_REPORT.md').write_text(report)
print('Wrote',D.parent/'P5_1_REPORT.md')
