# Autonomous Laravel collector

Operator starts one campaign-aware worker and one Artisan controller. Laravel handles every subsequent batch and stage; Codex, an LLM and a scheduler are not involved.

1. Load existing prepared PostgreSQL configuration and first-start the execution window.
2. Controller obtains a global orchestrator session mutex. Worker obtains a separate global worker mutex.
3. Only the original DurableCampaign session lock authorizes HTTP/import writes. Controller holds it during discovery, selection and gates, then releases it. Worker claims it, rechecks campaign/generation/receipts, runs one bounded queue job and releases it. Controller never does HTTP while the worker owns this lock.
4. Scope index, discovered pool, current run and wait/discovery phase persist in campaign state. Discovery trees and committed page proofs remain in existing checkpoints. Selection, run/queue dispatch and control transition commit atomically.
5. Outstanding receipts, delayed retries or live queue leases prevent completion. An empty queue before discovery is not completion. Recover uses existing manager/receipt fencing; it does not re-import completed items.
6. After a bounded run, baseline IDs/content hashes/quality, canonical identifiers, failed jobs, duplicates/orphans/empty text, persisted identity and archive integrity are checked. Review-quality text is normal persisted content, not a technical job failure.
7. Exact canonical stage targets plus passing gates allow automatic advance15/50/100/150. At target, complete; at a safety condition or finite scope exhaustion, stop. Final curated report is stored in the existing campaign checkpoint registry (`final-report`). Current reports remain available from PostgreSQL.

The mutexes coordinate roles only: there is one campaign/HTTP authority, one worker and the existing shared HTTP pacer. No delegated stale owner token, second importer or parallel client exists. A PostgreSQL disconnect/SIGKILL releases session locks; new owners receive new UUID/generation fences. Persisted request reservations are not refunded. A stale registered owner without a live session does not indefinitely block terminal report recovery.

SIGINT/SIGTERM on the controller requests a persisted pause and exits at a safe boundary. Discovery can safely replay a committed prefix. Worker signals allow the current job to finish, then exit; queue worker signal handlers are restored between job calls. SIGKILL is not graceful: restart eligible processes and wait out receipt leases/retry_after. Terminal status never becomes running through ordinary resume or controller restart.

`paused` is also the prepared initial state; first explicit `run` starts that prepared campaign. Later pauses require explicit `resume` followed by `run`. No process or HTTP traffic is started by `prepare`, `status`, `report` or `--dry-run`. Raw archival remains disabled.

No migration was necessary: existing campaign state/checkpoints store configuration, preservation baseline, stage audits and final report. Historic P6 rows/evidence are unchanged. See [LOCAL_RUNBOOK.md](LOCAL_RUNBOOK.md) and [TEST_RESULTS.md](TEST_RESULTS.md).
