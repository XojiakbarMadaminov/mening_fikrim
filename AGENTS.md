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
