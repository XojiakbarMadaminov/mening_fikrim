# Mening Fikrim

- Read `docs/LEX_SCRAPING_PLAN.md`, `docs/LEX_SCRAPING_TASKS.md` and latest stage report before changes.
- Laravel 13 / PHP 8.5 / local PostgreSQL; six authorized business tables including scrape_run_items, Database Queue and database cache.
- Keep source adapters free of database writes. Use the universal DocumentImporter.
- Keep P0/P0.1/P1/P2/P3 evidence unchanged. Default tests must not contact Lex.uz. Bounded live tests use at most 30 distinct groups, one worker and at least 2000 ms global pacing. Two-worker/1000 ms benchmarks are offline only.
- Development DB is always `mening_fikrim_active`; tests use only `mening_fikrim_active_test`; never migrate:fresh against the application or existing user database.
- Run `vendor/bin/phpunit` and `vendor/bin/pint --test` for relevant PHP changes.
- P4 Corpus Collection MVP is explicitly authorized: persist usable needs_review text, protect verified content, content-level quality and bounded local recheck. No seventh business table is needed. 1000-document tests, full import and subsequent stages require separate explicit permission.
- Preserve existing documents and P2 failed_jobs; migrations only, no extra database or business table beyond scrape_run_items.
- No frontend, API, AI/RAG/embedding, new OCR technology, Redis/Horizon or deployment in P4. Keep production scheduling disabled; live one worker, >=2000ms and <=30 distinct groups with the existing request budget.

## Current authorized stage: P6.1 (2026-10-29)

The explicit P6/P6.1 user tasks supersede historical P4-only table/API/stage restrictions within their stated scope. P6 added four durable campaign/archive tables; P6.1 adds no migration. Read docs/PROJECT_CONTEXT.md, docs/DATABASE_SCHEMA.md and docs/P6_1_REPORT.md for current implementation. Historical P0–P6 evidence and stopped campaigns must remain unchanged.

P6.1 is prepared, not live-started: p6-1-completion-150-20261029,150NEW,3500HTTP,600selected IDs,stages15/50/100/150. Only an explicit operator start may begin its source traffic. Use documents:campaign worker plus documents:campaign run; one worker, >=2000ms, context<=400, run<=30. No implicit scope expansion or terminal resume. Keep raw retention and scheduling disabled.

Authenticated reporting APIs and allowlisted read-only assistant tools are authorized; arbitrary SQL/files/shell, campaign-control APIs, new LLM/OCR providers, embeddings/RAG, frontend and production deployment are not. Tests only mening_fikrim_active_test and no real Lex.uz calls; preserve development data and existing staged files.

## Current authorized collection (2026-10-29)

User explicitly requested starting another10,000documents. P6.1 actually completed150NEW; terminal records must remain unchanged. New fixed profile corpus-10000-20261029 authorizes10,000NEW,120,000HTTP,25,000selectedIDs,7elapsed days,stages30/100/300/1000/3000/6000/10000. Read docs/COLLECTION_10000_REPORT.md and docs/collection_10000/README.md. Only this exact extended profile has larger application/SQL bounds (migration000012). Preserve1worker,>=2000ms,run<=30/context<=400,all stop conditions,quality/identity guards,disabled scheduling/raw retention. No automatic stop bypass or extra campaign.

## Current authorization: 30,000 collection (2026-10-30)

User explicitly requested fixing large-scale discovery and continuing with a30,000target. Treat as30,000additional NEW in corpus-30000-20261030,360,000HTTP/75,000selections/21elapsed days,397fixed queries(2015–2025 nat/loc/oliy plus loc30.12.2021). Read docs/COLLECTION_30000_REPORT.md and docs/collection_30000/README.md. Preserve stopped10,000campaign; only this exact new successor may follow its unreconciled_discovery_partition stop. Single-day64pages; other dates8pages; persist gaps and continue,never mark incomplete scope complete. Keep1worker,>=2000ms,run<=30/context<=400 and terminal access/budget/technical/identity/integrity guards. No automatic safety-stop bypass, extra campaigns, scheduling or raw retention. Migration000013extends only this named profile's SQL caps.

## Explicit operator recovery (2026-11-01)

User instructed parsing to continue after the reviewed404 incident. Same corpus-30000-20261030; no new campaign/reset/scope expansion. Read docs/collection_30000/RECOVERY_20261101.md. documents:campaign recover-not-found --failed-job=25 is a narrow explicit recovery of an already resolved404 with full audit,unchanged counters/deadline,retained failed row/SHA acknowledgement and operator-recovery-1 journal. New source404 outcomes are terminal review diagnostics,not fake content or failed queue jobs. Other failures remain guarded. Original final-report stays immutable; subsequent terminal report uses final-report-1. This is explicit recovery authority,not permission for automatic safety-stop bypass.

## Explicit transport recovery (2026-11-02)

User explicitly instructed continuation after source_http_0_repeated. SAME corpus-30000-20261030,no new campaign/budget/scope/time reset. Read docs/collection_30000/RECOVERY_20261102.md. recover-network requires15mincooldown,max3network/max10operator recoveries,original budgets,full integrity,source-observed GET and valid preserved receipts;403/429cannot be bypassed. probe-network must use existing paced/budgeted LexHttpClient before actors restart. Both actors reject pending probes. Source failures still terminal;no automatic recovery loop. Journal operator-recovery-2 preserves stop and receipt hashes;final-report-2 is the next epoch,old snapshots stay immutable.

## Explicit bounded HTTP0 waiting authorization (2026-11-03)

User requested avoiding manual shutdown/restart on source_http_0_repeated. Only the exact same30,000campaign may explicitly enable documents:campaign transport-wait. Read docs/collection_30000/TRANSPORT_WAIT_20261103.md. Once enabled, two HTTP0 transport failures open a durable circuit while campaign status remainsrunning. Worker waits; controller performs one paced/shared-budget source-observed GET after15min,then30/60min if still unavailable. Maximum8probes/episode,12episodes/campaign; originalHTTP/source/time/context bounds never reset. HTTP200/404close only the transport circuit,not quality/identity uncertainty. Access403/429,repeated5xx,source retry exhaustion,dependency/identity/integrity and all budget/deadline stops remain terminal. No general automatic terminal recovery is authorized. The prior stopped epoch4was preserved via explicit guarded recovery5before circuit opt-in; final-report-5is the next terminal epoch. No new campaign,migration,scheduler or AI work.
