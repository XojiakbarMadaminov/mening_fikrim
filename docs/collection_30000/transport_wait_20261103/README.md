# Bounded transport waiting evidence

Report: [TRANSPORT_WAIT_20261103.md](../TRANSPORT_WAIT_20261103.md).

- `baseline.json`: immutable MAIN pre-change campaign, all prior identity/content/quality baselines, failed-job/checkpoint/history hashes, original bounds and20pending receipt hashes.
- `baseline_tests.txt` / JUnit: initial267-test suite with one pre-existing clock-sensitive OCR recovery timestamp assertion failure. Final assertions retain all substantive equality and timestamp monotonicity.
- `focused_tests.txt`, `focused_tests_final.txt`, `interrupted_import_debug.txt`: retained intermediate offline fixture diagnostics (Laravel does not record thrown fake connections with assertSentCount; repeated Http::fake adds rather than replaces callbacks).
- `focused_corrected.txt`:28tests/253assertions PASS.
- `final_tests.txt`, `final_junit.xml`:279tests/1,432assertions PASS; no live source requests in tests.
- `pint_all.txt`: pre-existing historical P3 formatting issue only. `pint_changed.txt`: changed files PASS. The read-only audit script separately passed Pint.
- `pre_execution_audit.json`, `health.json`: clean full MAIN preservation and existing native dependency health before recovery.
- `operator_recovery.json`, `enable_policy.json`, `resume.json`: original official recovery5, explicit bounded policy opt-in and resume; no counter/bound reset or new campaign.
- `live_probe.json`: actual single source-observed GET, HTTP200,83,169→83,170requests; no duplicate/hidden retry.
- `after_policy_probe_audit.json`: read-only snapshot taken during/around initial probe; consult pre_worker_audit for settled post-probe state.
- `pre_worker_audit.json`: settled clean audit, circuit closed and all20original receipts unchanged.
- `bounded_live_work.txt`, `bounded_import_audit.json`: one real import through existing pipeline; one NEW canonical/three contents persisted, failedjobs43unchanged, audits pass.
- `actors.json`: actual detached actor commands, PIDs, logs and launch time.
- `live_verification.json`: timestamped autonomous progress and complete prior-data/history preservation after actors start.

Read-only, MAIN-only audit (no source traffic or state repairs):

```bash
php docs/collection_30000/transport_wait_20261103/audit.php
```

Do not replace original baselines, stopped campaigns or historical terminal snapshots. New observations should use new files. A running campaign may be waiting for transport: inspect `transport_wait.active`, `next_probe_at` and current source counters, rather than assuming process presence proves an import.
