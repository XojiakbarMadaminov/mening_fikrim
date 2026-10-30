# P6 initial audit and continuation state

P6 is authorized by the original user task and its subsequent HTTP performance continuation. P5/P5.1 terminal campaigns must never reopen. Existing evidence remains frozen.

Actual baseline:229documents/625identifiers/541contents (449verified/92review),45runs/644items,24historicalfailedjobs,0jobs,9migrations. Existing suite172tests/719assertionsPASS before new integration. Development only mening_fikrim_active; tests only mening_fikrim_active_test. Code/evidence baseline:storage/app/p6_baseline/code.tar.gz,database_baseline.json,historical_baseline.json. No live P6 requests or development migration executed before this audit.

Current flow:discovery→shared LexHttpClient pacing/budget→document/card/PDF download→existing extraction→closed reciprocal identity/legal metadata/language availability→strict content quality→universal DocumentImporter→PostgreSQL+atomic durable run-item outcome.

Already durable:scrape_runs checkpoints and counters,scrape_run_items unique receipts/token/930slease/retry/outcomes,DatabaseQueue receipts. Source/business locks and stale-content/candidate guards protect imports. Legal status and content quality independent;unknown language availability is not absence.

Cache-dependent beforeP6:PilotCampaign active pointer,budget/selections/stages/stop state;BoundedDiscovery tree/prefixes/IDs;per-contextHTTP budgets,cooldown,laststart;local driver progressJSON. Cacheclear could erase coordination and pacing evidence. Source bytes are not archived;only hashes/parser/layout/quality evidence survives. Defaultlive/schedulerfalse,oneworker,>=2000ms,perrun30/request400 retained.

Smallest chosen extension:permanent scrape_campaigns registry+scrape_campaign_checkpoints,global PostgreSQL session advisory controller lock+UUID/generation/backendPID fence,atomic reserve-before-send budget+durable start timestamp/context counts. Existing PilotCampaign facade and BoundedDiscovery algorithm will routeP6 state to permanent storage;historical cache/ledger unchanged. Conservative crash reservation can burn a token,never refill it. Source adapters stay free ofDB writes.

Archive requires separate immutable blob and observation tables to deduplicate bytes while retaining alias/fetch provenance. Private configurable localLaravelStorage,hash paths,atomic rename,size/quota/hash checks. No sanitizing and falsely callingmodifiedHTML original:token-bearing HTML is refused. Retention is disabled bydefault unless permission is established;historical terms are insufficient proof. This limitation is explicitly allowed inP6 and must be measured/reported. Reprocessing onlycomplete verified archived graph+cards,existingparser/importer,original observation time,zero network;bounded/dry-run.

At usage interruption, new durability and archive classes/migrations existed but were not integrated,not migrated in development,and not fully tested. PDF/manualQA helpers and archive tests were incomplete. The continuation preserves those files and finishes them. Original172test baseline is not being rerun unnecessarily. Git status/working tree are inspected separately;pre-existing staged Python cache files are not touched.

HTTP efficiency audit:each3-language graph normallyneeds3HTML+3cardGET=6requests even without duplicates;5or fewer cannot be promised without evidence supporting metadata reuse. Proven candidates:within-fetch memoization,same-campaign knownalias admission skipping,completed durable pagination reuse,offlinearchive extraction. Do not skip independent card/requisite checks or assume URL unchangedforever. Benchmark direct before/after with deterministic source fixtures and equal canonical/language/hash/quality outcomes;allliverequests stillunderone worker/2000ms/finitebudget.

Pending:facade/HTTP/job hooks,pause commands,durable discovery integration,archive/reprocess regression tests,request diagnostics/memoization benchmark,migrationpreservation/cacheclear/crash tests,source policy check,smallPDF/OCRpreflight,then mainstages30→100→300→600→1000onlyifgatespass. No largecollectionhasstarted. Keepfive unresolved IDs excluded:1587862,-1587862,1587860,8354875,-8345596.

## Continuation checkpoint

Durability, archive/reprocess integration and HTTP optimization are implemented. Full suite: 199 tests / 847 assertions PASS; Pint and historical 16 + 37 offline tests PASS. Non-destructive development migrations applied; baseline content and historical ledger/evidence preserved. Validation is running under the fixed 2,000-request budget; the main 1,000-new campaign is not started. A source-backed English-label normalization fix required a controlled pause/restart and explicit runtime parser-version attribution correction for diagnostic observations. See README.md and PostgreSQL campaign state for current live progress.
