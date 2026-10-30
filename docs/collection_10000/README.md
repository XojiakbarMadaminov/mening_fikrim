# Authorized 10,000-new-document collection

Status: LIVE RUNNING, first start2026-10-29 18:32:50 Asia/Tashkent. Worker/controller are detached OS sessions and have made actual HTTP200 requests. Collection is ongoing; no claim of10,000alreadycollected.

The user explicitly authorized another 10,000 documents and asked Codex to start Laravel processes before handing off. This is a separate finite collection campaign, not a full Lex.uz crawl. Existing P6/P6.1 campaigns remain terminal and unchanged.

## Baseline

At 2026-10-29 18:29:50 Asia/Tashkent: 1,229 canonical documents, 3,557 identifiers, 3,104 content representations (2,705 verified / 399 needs_review), 24 historical failed jobs. P6.1 completed its 150 NEW target using 1,122 HTTP, with zero technical failed items and zero pending jobs. `predecessor.json` and `baseline.json` retain actual evidence. Corpus target after this campaign is 11,229 documents if all 10,000 NEW are safely collected.

## Fixed authorization and bounds

ID: `corpus-10000-20261029`. Target: 10,000 additional canonical documents, excluding known identifiers, aliases and language representations. Stage gates: 30 / 100 / 300 / 1,000 / 3,000 / 6,000 / 10,000. Maximum: 120,000 HTTP requests, 25,000 distinct selected IDs, 604,800 seconds (7 elapsed days from first start, later downtime included). Every request, retry, PDF and discovery call uses the original PostgreSQL budget/pacer.

One worker, >=2,000ms request-start interval, <=30 selections per run, <=400 HTTP per context. Page8, depth10, per-query127nodes, global1,024nodes /100,000discoveredIDs and existing1,024context cap remain finite. HTTP403/429, repeated failures, technical failed items, integrity violations, exhausted scope/budget/duration and unreconciled discovery cause terminal stop. There is no automatic stop bypass or replacement campaign. Quality review remains non-blocking and verified text remains protected.

Scope: 144 fixed inclusive monthly queries, roots nat/loc/oliy, years2021–2024, statusY. Source categories and query mechanisms are existing tested ones. Some queries overlap previous campaigns intentionally; known IDs and aliases are excluded by the existing collector. Listing counts/yield are measured during execution; no global completeness or guaranteed10,000yield is claimed. Scope is never expanded at runtime.

Planning: P6.1 observed7.48HTTP and1.47selectedIDs per NEW, about45minutes for150 at that mix. 10,000 at that mix implies~74,800HTTP/~14,734selections/~50hours. Bounds add uncertainty headroom; PDF/OCR, duplicate yield, audits and network changes can substantially alter this estimate. This is not a promise to finish overnight. Machine, PostgreSQL and internet must stay available; prevent sleep. No automatic reboot recovery/supervisor is installed.

## Minimal implementation

`config/corpus_campaigns.php` adds an explicit immutable extended profile. Autonomous preparation accepts its finite limits. DurableCampaign admits larger limits only when every extended-profile field matches (autonomous/type/target/budgets/scope/stages/predecessor/limits); ordinary CLI creation retains1,000target/15,000HTTP/3,500selection caps. Existing safety-stop fencing remains authoritative.

Additive migration `2026_10_29_000012_allow_bounded_corpus_collection.php` transactionally replaces two CHECK constraints. Extended SQL bounds apply only to the named corpus_collection ID; historical campaign types keep their original ceilings. No tables/columns/rows are removed or rewritten. Rollback refuses when larger campaign records exist, preserving evidence.

No new parser, OCR, AI or storage technology. Raw retention and scheduling remain disabled. Existing source policy/reuse limitations remain unresolved for commercial redistribution, and the existing quality/manual-review/unknown-language limitations remain visible.

## Verification

Initial225-test pass attempt recorded one migration rollback test failure after the additive migration was introduced: hardcoded8steps no longer removed the documents table. Adjusted to9steps, preserving all assertions. First new test also compared transient owner-lock fields; it now asserts immutable stored-row equality across repeated prepare.

Focused18tests/128assertions PASS. Full231tests/1,077assertions PASS (93.571s); Pint PASS. Migration000012 applied in10.70ms; prelaunch preservation audit errors[]. WorkerPID2168363/controllerPID2168364 are detached separate session leaders; launch_status.json/processes.json record actual startup. Initial plain shell-background launch did not survive the command execution environment; no source requests were made by those exited attempts. Relaunched using nohup with independent process sessions and verified both alive across subsequent tool calls. Migration preservation and launch evidence are recorded in this directory. Default tests use only mening_fikrim_active_test and mocked HTTP. The development database is never reset/truncated.

`audit.php baseline` captured pre-migration canonical/identifier/content IDs, hashes, quality and historical campaign row hashes. `audit.php check` verifies preservation plus duplicate/orphan/nonempty/hash/identity/archive/failed-job invariants. Laravel performs the existing integrity audits throughout batches and stages.

## Operate

From `/home/xojiakbar/PhpstormProjects/mening_fikrim`:

```bash
php artisan documents:campaign status --id=corpus-10000-20261029 --json
php artisan documents:campaign report --id=corpus-10000-20261029 --format=markdown
```

Start only if these actors are not already running:

```bash
nohup env SCRAPING_LIVE_ENABLED=true php artisan documents:campaign worker --id=corpus-10000-20261029 > storage/logs/corpus-10000-worker.log 2>&1 < /dev/null &
nohup env SCRAPING_LIVE_ENABLED=true php artisan documents:campaign run --id=corpus-10000-20261029 > storage/logs/corpus-10000-controller.log 2>&1 < /dev/null &
```

One controller and one campaign-aware worker cooperate automatically; never add ordinary queue:work or independent source requests. Closing the terminal/Codex does not stop detached processes. Reboot/process crash still requires an operator restart using the SAME campaign and durable counters. Pause via `documents:campaign pause --id=corpus-10000-20261029`; recoverably paused campaigns require explicit `resume` before restarting the controller. Terminal campaigns cannot ordinarily resume. Stop via `documents:campaign stop --id=corpus-10000-20261029 --reason=operator_requested`.

After terminal completion/stop:

```bash
php artisan documents:campaign report --id=corpus-10000-20261029 --snapshot=final --format=markdown
php docs/collection_10000/audit.php check
```

Completion remains pending until the actual durable NEW count is10,000 and final integrity/receipt gates pass. This launch report must not be interpreted as a final collection report.

Latest launch verification at2026-10-29T18:34:31+05:00: statusrunning,5NEW/10000,total1234documents,11newcontent representations,HTTP50/120000,technicalfaileditems0,preservation errors[]. Running collection, not final completion.
