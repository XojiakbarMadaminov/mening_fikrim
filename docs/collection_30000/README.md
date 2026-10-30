# 30,000 new canonical documents — controlled collection

Status: LIVE RUNNING from2026-10-30 10:03:59Asia/Tashkent. Actual launch and persistence evidence follows. This document does not claim that 30,000 documents have already been collected.

## Authorization and baseline

On 2026-10-30 the user explicitly requested continued large-scale collection with a new 30,000 target after the previous discovery stop. We interpret this as 30,000 additional NEW canonical documents in a separately budgeted campaign. Existing documents, aliases and language variants do not count toward that target.

Baseline: 3,667 documents / 11,399 identifiers / 8,922 contents; 7,145 verified / 1,777 needs_review; 24 historical failed jobs; zero pending jobs. Previous corpus-10000-20261029 stopped at 2,438 NEW, 17,040 HTTP, 3,546 selections, on 2026-10-30 06:28:11 Asia/Tashkent. Its final immutable report and registry row remain unchanged. Baseline and historical row hashes are in baseline.json; predecessor_final.json preserves the stored final report.

## Root cause and focused fix

The former collector used at most eight pages even for a single day. The observed loc / 30.12.2021 partition listed397 results, exceeding the derived160-result capacity of eight20-result pages. Date subdivision could not split a single day, so the entire campaign stopped with unreconciled_discovery_partition. This was a coverage boundary, not a technical extraction failure or access denial.

The NEW profile keeps eight pages for divisible multi-day partitions and allows at most64pages for single-day partitions. A397-result day can now enumerate20pages through the existing ASP.NET pagination. Prefix/total drift, cycles and end-count checks are unchanged. Source availability/counts may change; actual live re-enumeration is recorded after launch, not inferred from the regression fixture.

If even the finite extended budget cannot enumerate a partition, the existing checkpoint remains incomplete. The controller records query, listing total, discovered count, reason and time in durable coverage_gaps, keeps the usable discovered IDs, and proceeds to the next fixed interval. Identity, status and extraction validation still apply independently to every candidate. A gap is never called complete. Unexpected source/pagination exceptions still stop; only an explicitly returned incomplete coverage result has become non-blocking for this named profile. Old profiles retain their original stop policy.

A collection target can be reached while scope coverage has gaps. The current/final JSON and Markdown reports expose those gaps; they must not be interpreted as global Lex.uz coverage. No unfinished partition is silently reset or re-downloaded in a loop.

## Immutable campaign bounds

ID: corpus-30000-20261030. Target30,000 NEW. Stages30/100/300/1,000/3,000/6,000/10,000/20,000/30,000. Maximum360,000HTTP,75,000selected IDs,21elapsed days (1,814,400seconds, including later downtime). One worker, >=2,000ms global request-start pacing, <=30selections/run, <=400requests/context. All discovery/cards/variants/PDF/retry/redirect traffic shares the same committed PostgreSQL budget; process restarts never replenish it.

Finite discovery scope: first the problematic loc/30.12.2021 day, then396monthly nat/loc/oliy queries for2015–2025, statusY. Total397queries. Existing identifiers and selected IDs are excluded. This scope is fixed before execution; its eligible yield is not guaranteed. Supported filters/endpoints are unchanged. Global ceilings8,192nodes/500,000unique discovered IDs,16,384contexts,depth10,127nodes/query; single-day64pages,other partitions8pages. Contexts are bounded per discovery node so a multi-day tree does not exhaust one400-request context; every context still draws from the same cumulative campaign budget.

Planning evidence from the former run: ~204.5 NEW/hour, ~6.99 HTTP/NEW, ~1.45 selections/NEW. At the same mix30,000 would take~147hours (~6days), before changes in duplication, discovery, PDF/OCR, audits and downtime. The caps allow finite headroom; they do not guarantee target yield, completion time or source permission for unrestricted crawling.

Access403/429, repeated network/server failures, exhausted request/source/time/node/context budgets, technical failed items, unsafe identity/preservation/archive, foreign receipts and source-prefix drift remain terminal safety stops. Ordinary resume cannot reopen them. There is no automatic replacement campaign or budget reset. The previous discovery-stopped campaign is not reopened: only the exact new authorized successor may follow its unreconciled-discovery stop. Access/integrity stops cannot use that exception.

## Scale-related memory handling

The drained discovery candidate pool is replaced when moving to the next partition, rather than retaining every historical listing row. All discovered evidence stays in the existing checkpoints. Selected-ID and canonical uniqueness rules remain unchanged.

Content-integrity hashing reads10rows per database query instead of relying on PDO's buffered full-content cursor. Run-item failure/identity checks use SQL EXISTS. Cross-checkpoint node/unique-ID accounting is performed on PostgreSQL, transferring distinct opaque row IDs instead of all previous page trees. Reporting projects only the required item outcome/format fields and iterates checkpoints/documents in bounded batches. No validator or identity check is removed to improve throughput.

## Schema and implementation

Migration2026_10_30_000013_allow_bounded_30000_collection transactionally replaces two campaign CHECK constraints, permitting the new caps only for the exact named30,000 campaign. Historical10,000/pilot bounds remain intact. No new table, column, reset, truncation, row deletion or counter rewrite. Rollback refuses if the new campaign exists, preserving evidence.

Main files: config/corpus_campaigns.php; AutonomousCampaign; BoundedDiscovery; DurableCampaign; CampaignIntegrity; CampaignReportingService; migration000013. No scraper rebuild, new OCR/PDF technology, AI, frontend, deployment, scheduler or raw retention.

## Validation

Baseline231tests/1,077assertions PASS before edits. Focused59tests/365assertions PASS after correcting an intermediate audit-query implementation error (collection was inadvertently retained before SQL EXISTS). Existing real offline controller/worker/SIGTERM/SIGKILL tests are retained. A synthetic397-row/20-page fixture tests the newly authorized pagination; overflow remains explicitly incomplete, and the controller-gap test proves next-partition progress without declaring coverage complete. SQL caps, token preservation, exact successor, access-stop protection and canonical/quality regression tests are included. Additional opaque-ID checkpoint regression covers array/map representations and duplicate IDs across checkpoints.

All default tests use only mening_fikrim_active_test and mocked HTTP. Full suite/Pint, main migration preservation and real first-day results are appended below. audit.php uses the fixed development database and verifies old canonical/identifier/content hashes/quality, historical campaign rows, duplicate/orphan/nonempty/identity/archive invariants and old failed jobs. Existing stopped campaign/evidence rows remain immutable.

## Commands

Project directory: /home/xojiakbar/PhpstormProjects/mening_fikrim

```bash
php artisan documents:campaign status --id=corpus-30000-20261030 --json
php artisan documents:campaign report --id=corpus-30000-20261030 --format=markdown
```

The running campaign uses these existing Laravel actors. Do not duplicate active actors:

```bash
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign worker --id=corpus-30000-20261030
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign run --id=corpus-30000-20261030
```

Detached processes use nohup and separate process sessions. Logs: storage/logs/corpus-30000-worker.log and corpus-30000-controller.log. They survive closing Codex/terminal; the computer, PostgreSQL and network must remain available. Reboot/process crash still requires restarting the SAME eligible campaign; automatic system supervision/scheduling was not added. Pause: documents:campaign pause --id=corpus-30000-20261030. Only recoverably paused campaigns permit explicit resume; terminal safety stops do not. Stop: documents:campaign stop --id=corpus-30000-20261030 --reason=operator_requested.

Final report after termination:

```bash
php artisan documents:campaign report --id=corpus-30000-20261030 --snapshot=final --format=markdown
php docs/collection_30000/audit.php check
```

Remaining limitations: manual quality review, unknown language availability, PDF/OCR fidelity, source permission/commercial reuse, no raw archive, finite scope yield, later unavailable partitions, long-running machine reliability and changing format distribution. Non-blocking incomplete discovery explicitly sacrifices a global-coverage claim; it does not fabricate completeness or content.

## Verified preflight and launch

Full241tests/1,123assertions PASS (100.321s); Pint PASS. Migration000013applied in5.26ms,13total migrations/16tables. Main preservation audit errors[] before launch; historical campaign rows/content hashes retained. Started at2026-10-30 10:03:59Asia/Tashkent using detached nohup sessions: workerPID2961657,controllerPID2961658. Both remained alive across subsequent commands. First live scope is loc30.12.2021. Actual source enumeration/persistence is appended separately; this startup is not a30,000completion claim.

## Actual source and persistence verification

At2026-10-30T10:05:30+05:00, real loc30.12.2021 enumerated all397/397distinct IDs in20pages,zero restarts,complete=true. Existing source URL and committed page-prefix fingerprints are retained in problem_day_live.json. This is an actual live result using the shared budget/pacer, not a synthetic-fixture claim.

At2026-10-30T10:05:03+05:00: statusrunning,2NEW/30000,total3669documents,4new stored representations,HTTP29/360000,technicalfaileditems0. Historical data/campaign preservation errors[];coverage gaps[]. Both detached actors remained alive. Counts continue changing; handoff_summary.json/launch_status.json are timestamped snapshots, not a final collection report.
