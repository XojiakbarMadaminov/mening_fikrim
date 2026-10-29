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
