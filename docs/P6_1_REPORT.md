# P6.1 — autonomous collector and reporting handoff

**Implementation ready; live collection NOT started.** Separate campaign `p6-1-completion-150-20261029` is prepared paused at0/150NEW,HTTP0/3500,selected0/600. P6 remains stopped at850/1000 with bounded_discovery_scope_exhausted. Combined actual NEW since P5.1 remains850;150are pending operator execution. Expected total1229is a future acceptance target, not a collected count.

## Existing infrastructure reused

Universal DocumentImporter, HTML/PDF/Tesseract pipeline, identity/requisite/language/quality rules, verified-content candidate protection, durable run/items, PostgreSQL ownership/fencing, atomic HTTP budgets, shared pacing, recursive bounded discovery, private archive and offline replay are retained. No scraper rebuild or extraction engine change.

Baseline and handoff corpus:1079documents,3104identifiers,2763contents;2471verified/292needs_review;24historical failed jobs,0pending jobs,0real raw blobs. All historical rows and P6 evidence hashes pass preservation. New campaign/checkpoint rows are additive; no migration, new business table, reset/truncate or historical campaign modification occurred. Actual schema:16tables/11migrations, generated in DATABASE_SCHEMA.md.

## Implemented autonomy

`AutonomousCampaign` moves P6's proven controller flow into Laravel. A long-running campaign-aware worker and one controller run in separate terminals. Coordination mutexes reject duplicate actors. They hand over the original DurableCampaign lock; only its current UUID/generation/backend-PID owner may perform source HTTP/import writes. There is no parallel/delegated HTTP authority.

Durable configuration, scope index/pool, phase/current run, preservation baseline, stage audits and final report are kept in existing campaign state/checkpoints. Run dispatch/control commit atomically. Pending receipts, delayed retries and missing worker jobs remain unfinished work; an empty queue does not mean completion. Existing recovery/fencing handles restart without duplicate creation. Stage15→50→100→150advancement and integrity gates are deterministic, automatic and independent of Codex or a model.

Controller SIGINT/SIGTERM requests a persisted safe-boundary pause. Worker signals finish the current job, then exit; SIGKILL releases PostgreSQL session locks and leaves receipts/budgets intact. Eligible restart reuses the same rows. Terminal stops cannot ordinarily resume. Active owners and stale registered owners are distinguished for terminal report recovery. Operator stops and normal/critical termination save curated final reports in `final-report` checkpoints.

## Prepared scope and safety

New finite queries: nat/locMarch2020,oliyQ1/Q22021,nat/locApril2020; see [CAMPAIGN_SCOPE.md](p6_1/CAMPAIGN_SCOPE.md). They extend supported categories beyond the exhausted P6 periods. Existing identifiers, selected IDs and five unresolved conflicts are excluded. No listing count/yield was fabricated or verified live while coding.

Limits:150NEW,3500HTTP,600source IDs,one worker,>=2000ms,context400,run<=30,page8,depth10,globalnodes255/discoveredIDs20000,perquerynodes127,existing capture/file/archive/OCR safeguards. First explicit operator run begins a six-hour elapsed execution window; it includes later pauses/downtime and is stricter than six active hours. Prepared waiting is excluded; resume never resets the window or counters. Scope/budget/integrity/access stops remain terminal.

Raw retention stays disabled because permission remains unresolved. Existing real archive blobs remain0; offline replay fixtures pass the full suite. No source traffic or raw backfill occurred.

## Reporting and AI-readable interface

CampaignReportingService reads actual PostgreSQL data in repeatable-read/read-only transactions. CLI JSON/Markdown and nine authenticated GET/HEAD API routes report campaign/corpus counts, per-language quality and availability, scope/checkpoints, HTTP budgets, failures, activity, elapsed throughput and comparisons. Unavailable legacy/active metrics are explicit. Canonical/source/alias/content units, technical failure/review, unknown/unavailable language and automated verified/human accuracy remain distinct.

No operational user/login model or supported Ollama provider existed. Laravel request authentication plus Gate provides a fail-closed, read-only machine credential using a SHA256 bearer digest; no auth table/provider package was added. API defaults disabled until the operator configures its credential. It exposes no campaign control, SQL, shell, arbitrary files, owner tokens, raw exception/log payloads or credentials. Unexpected errors are sanitized even in debug mode.

CorpusReportingTools supplies seven approved read-only functions for an external AI client. A bounded Uzbek intent adapter returns actual tool evidence without invoking an LLM. No Ollama/AI provider was introduced; deterministic reporting and scraping remain independent of any model. This is not general chat or legal analysis. [REPORTING_API.md](p6_1/REPORTING_API.md) documents authentication, tools, limitations and examples.

## Validation

Initial201tests/860assertions PASS. Final **225tests/1053assertions PASS**,Pint PASS. Real offline two-process CLI campaign, SIGTERM, SIGKILL, restart/lease/duplicate/stale-owner checks, identity/quality persistence, SQLSTATE25006 read-only enforcement, auth401/403, GET-only API, safe tool allowlist and credential/SQL leak prevention passed. Existing parser/P0–P6 Laravel regressions remain passing. See [TEST_RESULTS.md](p6_1/TEST_RESULTS.md).

Main database audit reports no changed/deleted historical row or P6 evidence file. Repeated prepare/dry-run caused no import/source requests and no duplicate campaign. Generated schema is actual introspection, not a proposed model. No destructive test touched the development corpus. Tmux was not installed and its optional documented commands were not claimed as executed.

## Main files changed

- Scraping: `AutonomousCampaign`, `AutonomousCampaignWorker`, `CampaignSessionMutex`, `CampaignIntegrity`; focused DurableCampaign extensions and existing ManageCampaign command.
- Reporting: `CampaignReportingService`, `CorpusReportingTools`, `CorpusReportingController`, API routes, Laravel auth/gate/bootstrap bindings, corpus campaign/reporting configuration and .env.example digest setting.
- Tests: AutonomousCampaignTest, CorpusReportingTest, isolated subprocess fixtures and exact authorized-route foundation checks.
- References: PROJECT_CONTEXT.md,DATABASE_SCHEMA.md,p6_1plan/runbook/API/scope/test evidence; README and project progress docs.

## Operator handoff

Terminal1:

```bash
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign worker --id=p6-1-completion-150-20261029
```

Terminal2:

```bash
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign run --id=p6-1-completion-150-20261029
```

Read-only:

```bash
php artisan documents:campaign status --id=p6-1-completion-150-20261029 --json
php artisan documents:campaign report --id=p6-1-completion-150-20261029 --format=markdown
```

[LOCAL_RUNBOOK.md](p6_1/LOCAL_RUNBOOK.md) provides pause/resume/stop, crash recovery, optional tmux and terminal report instructions. No Codex supervision is needed between batches/stages. Supervisor/systemd, scheduler and deployment were not configured.

## Remaining risks and decision

**GO for an explicit operator start within this fixed prepared scope; live150-target acceptance remains pending.** Finite scope may yield fewer than150; new dates/counts have no live validation yet. Stop and report true results if it exhausts. Raw-retention permission,11-text/10-OCR P6 sample limits,humanQA,source identity conflicts,unknown language coverage and global discovery gaps remain. API credentials must be configured locally; optional tmux installation and process restart after machine shutdown are operator responsibilities. No new LLM integration exists. Full import,scheduler,production,frontend,chunking,embeddings,RAG and database cloning remain out of scope.
