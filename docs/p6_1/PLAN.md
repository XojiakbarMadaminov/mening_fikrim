# P6.1 implementation plan and initial flow audit

P6 is terminal at850 NEW canonicals,1079documents/3104identifiers/2763contents; its scope is exhausted. This task authorizes implementation, a separately prepared150-target campaign and read-only reporting. No live source traffic is authorized during preparation.

Existing flow: P6 PHP evidence controller -> DurableCampaign PostgreSQL ownership -> BoundedDiscovery -> ScrapeRunManager -> database scraping queue -> existing extraction/identity/quality/importer -> durable run items. The evidence controller advances stages automatically but runs bounded queue workers inside itself. A standalone worker exits because it does not own the campaign advisory lock. Ordinary creation rejects a stopped predecessor. CLI reports are minimal state/archive JSON; no operational API authentication or AI provider exists.

Chosen minimal extension: Artisan orchestrator and worker reuse the same exclusive P6 campaign lock by handing ownership over between discovery/controller work and bounded queue jobs. Separate session mutexes reject duplicate controllers/workers. No delegated owner token, competing HTTP authority, new state machine, parser or extraction engine. Durable control/checkpoints retain scope, phase, current run, stage audits and preservation baseline. SIGTERM/Ctrl+C pause at a safe boundary. Operator stop is signalled then acknowledged by the active owner. Crash recovery keeps request counts and receipt leases.

New fixed campaign: p6-1-completion-150-20261029. Target150; stages15/50/100/150; HTTP3500; source selections600; one worker;>=2000ms; context400; page8/depth10/partition255; six-hour elapsed execution window from first operator start (a conservative bound including later pauses/downtime). Raw retention stays disabled. Prepared in paused state with zero requests. Only this explicitly authorized successor of scope-exhausted P6 is allowed; access/integrity/budget stops cannot be bypassed.

Scope is finite, disjoint from old query/date pairs: nat/loc March2020, oliy Q1/Q2 2021, nat/loc April2020. These use proven roots and new periods; listing/canonical yield is unverified until the operator starts. Known identifiers and unresolved IDs remain excluded. No guessed listing counts.

Reporting: PostgreSQL read-only repeatable-read snapshots, curated safe fields, bounded diagnostics and allowlisted report tools. Laravel request guard + gate with configured SHA256 bearer-token digest (fail closed); no users/auth tables currently exist. Deterministic Uzbek reporting is independent of LLM availability. No Ollama integration is installed; do not introduce a provider or scrape dependency.

Validation: preserve baseline/P6 hashes; original201-test suite; focused autonomous/locks/recovery/report/auth tests; full suite/Pint; offline fake transport only; prepared campaign dry-run with zero HTTP. Document local two-terminal/tmux operations and actual schema introspection.
