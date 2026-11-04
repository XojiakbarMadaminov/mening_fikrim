# Bounded automatic transport waiting — 2026-11-03

The operator explicitly requested that `source_http_0_repeated` should not require stopping and manually restarting collection every time. This supersedes the former manual-only HTTP0 policy for the **same** `corpus-30000-20261030` campaign, when explicitly opted in. Other campaigns and all other terminal safety conditions retain their behavior.

## Problem and starting state

At 12:24:32 +05 the campaign stopped after two connection failures while fetching the already observed `/uz/actinfo/card1/6240957` path. Both detached actors exited as previously designed. The database had 11,456 NEW / 30,000; 15,123 documents; 45,571 identifiers; 37,083 contents (28,696 verified, 8,387 needs_review); 43 historical failed jobs; and 20 pending receipts (19 queued and one retry_wait). HTTP used83,169/360,000 and selected IDs17,849/75,000. There was no403/429 in its status history. This was a transport failure, not a claim of missing source content.

The immutable [baseline](transport_wait_20261103/baseline.json) records every prior canonical/content/identifier, old failed-job and checkpoint hashes, historical campaigns, pending receipts and original bounds. The pre-execution preservation audit returned no errors; all20receipts were unchanged. MAIN remains `mening_fikrim_active`; tests remain `mening_fikrim_active_test`. The AI worktree/database were not accessed. Existing staged changes and P0–P6 evidence were preserved.

## Implemented behavior

`documents:campaign transport-wait --id=corpus-30000-20261030` is an explicit, once-only opt-in. It requires eligible running/paused ownership, remaining original budgets, no access-denial history and a passing full integrity audit. It cannot reopen a terminal campaign. An immutable `transport-wait-policy` checkpoint records the prior state, policy and audit. State is stored in existing campaign JSONB; no migration/table/column or new infrastructure was added.

For subsequent consecutive HTTP0 connection failures:

1. Campaign status remains running with `transport_wait.active=true`; normal discovery/import HTTP is blocked by `assertOpen`.
2. The worker leaves queued work untouched. The controller keeps operating and checks persisted time boundaries without sending source traffic early.
3. First probe is after15minutes. It sends **one** source-observed GET through the existing LexHttpClient, global pacing, request/byte/time/context budgets and ownership fencing. There is no direct curl, alternate client, parallel worker or inline probe retry/redirect chain.
4. HTTP200/404 proves only transport reachability. The circuit closes and the original receipts can continue. A404does not create content or infer language/legal absence.
5. A failed HTTP0 probe persists a further30-minute wait, then60-minute waits. Next-probe time and spent attempt are committed **before** HTTP, so crashes/restarts cannot immediately resend or refund a probe.
6. At most8probes per outage and12outage episodes for this opt-in campaign are permitted (at most96probe reservations within the unchanged360,000HTTP budget). Exhaustion is terminal, with diagnostics. One uninterrupted outage can wait approximately6hours45minutes before exhausting eight probes.

Original21-day execution window, max400requests/context, max30selections/run, one worker and >=2,000ms request starts remain unchanged. All requests and failed probes consume existing cumulative budgets. The initial adoption of the already reviewed stopped incident can probe immediately because its mandatory15-minute cooldown has already elapsed.

HTTP403/429, repeated5xx, identity/quality-persistence/integrity violations, foreign ownership/receipts, dependency failures, source retry exhaustion and budget/deadline exhaustion remain guarded. The normal durable source retry limit of three is unchanged: repeated intermittent outages that exhaust the same source's attempts may still require review. This feature waits for recoverable transport outages; it does not promise unconditional endless execution or suppress technical failures.

## Current stopped campaign activation

The terminal12:24snapshot remains immutable. The existing guarded third network recovery was used, preserving original bounds/receipts and creating `operator-recovery-5`. It leaves a pending probe. The explicitly enabled circuit adopted that pending probe, cleared the old manual-probe gate only while setting its own active gate, and preserved counters. The next terminal snapshot uses `final-report-5`; earlier snapshots remain unchanged.

Executed commands (historical actions, do not repeat recovery blindly):

```bash
php artisan documents:campaign recover-network --id=corpus-30000-20261030
php artisan documents:campaign transport-wait --id=corpus-30000-20261030
php artisan documents:campaign resume --id=corpus-30000-20261030
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign transport-probe --id=corpus-30000-20261030
```

At15:05:01+05 the real source-observed metadata GET returned **HTTP200**. Exactly one request was spent:83,169→83,170. The circuit closed; its episode1/probe1 receipt and policy remain durable. Twenty pending queue receipts were retained. This is live transport evidence; no content-quality claim follows from a successful probe. [Probe output](transport_wait_20261103/live_probe.json).

## Changes

- DurableCampaign: explicit opt-in, persisted circuit/next probe, one-probe service, normal traffic gate, bounded outage/probe counters and source-observed GET provenance.
- AutonomousCampaign: handles the circuit before ordinary discovery/dispatch, retaining its existing controller ownership.
- AutonomousCampaignWorker: waits without claiming queue jobs while the circuit is open.
- LexHttpClient: one request per automatic probe; ordinary request pacing/retry/budgets remain effective.
- ManageCampaign: explicit `transport-wait` and bounded `transport-probe` actions; terminal manual probe results retain their final epoch report.
- CampaignReportingService: allowlisted circuit enabled/active/counters/next-probe fields in the recovery report. The legacy running status can coexist with transport waiting; inspect `transport_wait.active`.
- TransportWaitTest: offline circuit, deadline/budget/access/audit, restart, receipt and interrupted-import regressions.
- CampaignRecoveryTest: corrected a prior wall-clock-sensitive assertion. Re-fetch/check timestamps may advance; all other content/identity/hash/quality/provenance fields remain equal, and timestamps must be nondecreasing. No production importer behavior changed.

## Validation

The first baseline run exposed that existing OCR recovery test's full-row timestamp comparison crossing a real clock second (267tests, one failure). It was a test-contract issue, not content loss. A re-import legitimately updates `last_fetched_at` and `quality_checked_at`. The corrected regression retains all substantive equality checks and explicitly checks timestamp monotonicity.

Final complete offline suite: **279tests / 1,432assertions PASS**,124.310seconds,115MB. Existing267tests are retained plus12new circuit regressions. Focused corrected circuit/recovery subset28tests/253assertions PASS. HTTP fixtures prevented real Lex.uz traffic during tests.

Coverage includes normal opt-in isolation, preserved terminal snapshot before explicit legacy recovery, pending probe adoption, two HTTP0 errors without terminal stop, no traffic during cooldown, restart persistence, one counted request per probe,30/60-minute backoff, eight-probe stop,12-episode cap, deadline/budget/integrity refusal,429stop, repeated503stop,404reachability without fake content, unchanged queued receipts and interrupted source import completing with its original cumulative attempt history.

Pint passes changed PHP files and the new read-only audit. Whole-repository Pint still flags the pre-existing historical `docs/p3/content_audit.php` formatting issue; that evidence file remains untouched. Intermediate fixture-debug outputs are retained alongside final passing results. No tests or production data were reset outside the dedicated test database.

## Operations

Run the same actors once, after preflight and checking there is no existing actor:

```bash
php artisan documents:health --json
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign worker --id=corpus-30000-20261030
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign run --id=corpus-30000-20261030
```

Normal HTTP0 outages now require no operator restart while these actors and the approved circuit remain eligible. Health/access/integrity/exhaustion terminal stops still need investigation; no automatic terminal-stop bypass was added. Reboot/DB/process failure still needs an operator to restart the same eligible actors; no scheduler/systemd/Supervisor deployment was introduced.

Quick current state and read-only preservation evidence:

```bash
php artisan documents:campaign status --id=corpus-30000-20261030
php docs/collection_30000/transport_wait_20261103/audit.php
tail -n 20 storage/logs/corpus-30000-worker.log
tail -n 20 storage/logs/corpus-30000-controller.log
```

The default status exposes durable `transport_wait` counters and `next_probe_at`. While active, the expected behavior is no fresh source traffic until the due probe. The audit connects only to MAIN, reads a consistent read-only snapshot, verifies all prior IDs/hashes/quality/checkpoints/failures/bounds and reports receipt states. It never repairs rows or contacts Lex.uz. Do not overwrite the original baseline or historical final snapshots.

## Actual restart and fresh persistence

The settled pre-worker audit returned `errors=[]`, HTTP83,170 and all20original queue-row hashes unchanged. A bounded `work --max-jobs=1` then persisted one NEW document/three contents:15,124documents/37,086contents,HTTP83,178,43failedjobs unchanged. Verified/review counts became28,697/8,389. The full old-data/hash/quality/checkpoint/history audit remained clean.

Detached actors launched at **15:07:52+05**: workerPID794970,controllerPID794971,using the same existing commands/logs in separate process sessions. At **15:09:02+05**, a consistent read-only snapshot confirmed further autonomous progress:

| Metric | Before | Live observation |
|---|---:|---:|
| Campaign NEW / target | 11,456 / 30,000 | 11,458 / 30,000 |
| Total documents | 15,123 | 15,125 |
| Identifiers | 45,571 | 45,579 |
| Contents | 37,083 | 37,088 |
| Verified / needs_review | 28,696 / 8,387 | 28,697 / 8,391 |
| HTTP used / cap | 83,169 / 360,000 | 83,194 / 360,000 |
| Historical failed jobs | 43 | 43 |
| Pending receipts | 20 | 17 |

Both actors remained alive; campaignrunning,transport_waitenabled/activefalse,one counted HTTP200probe,zero new technical failed jobs. Last newly stored canonical wasdocument15125/source`-6241295`at15:08:22+05. Remainingtarget18,542NEW. All old canonical/identifier/content hashes/quality,old failed rows,original checkpoints,previous campaigns and original bounds passed the final audit. This proves live persistence after the policy change, not merely process startup. The snapshot can include a currently reserved in-flight HTTP token; reservations are intentionally spent before a response.

The actual incident was already reachable during the bounded live probe. Continued-outage waiting/backoff/exhaustion behavior was verified with explicit offline connection-failure fixtures, not fabricated live failures. No deliberate failure was injected into development data or source traffic. [Live verification](transport_wait_20261103/live_verification.json), [actor identities](transport_wait_20261103/actors.json).
