# 2026-11-02 MAIN OCR recovery evidence

Parent report: [OCR_RECOVERY_20261102.md](../OCR_RECOVERY_20261102.md).

- `baseline.json`: immutable task-start campaign, preservation rows, all checkpoint/previous-campaign hashes, affected items and 43 failed-job receipts.
- `baseline_tests.txt`, `baseline_junit.xml`: initial suite with one missing optional Python environment error.
- `early_tests.txt`, `early_junit.xml`: first existing focused regressions, 51 PASS.
- `focused_tests_before_fixture_fix.txt`: retained intermediate synthetic fixture failure (missing checkpoint version); final suite uses the corrected faithful fixture.
- `focused_tests.txt`: final focused regressions.
- `final_tests.txt`, `final_junit.xml`: full offline suite, 267 tests/1,315 assertions PASS.
- `pint.txt`: whole-repo check, pre-existing historical P3 formatting failure.
- `pint_changed_files.txt`: changed application/test files PASS. `inspect.php` separately passes Pint and executes real read-only audits.
- `dependencies.json`, `health.json`: actual native package hashes/versions, restored Python dependencies and successful offline preflight.
- `synthetic_scan.pdf`, `synthetic_ocr.md`, `synthetic_ocr.json`: explicitly synthetic image-PDF and actual native OCR output/diagnostics, not real source evidence.
- `pre_recovery_audit.json`: initial full audit, only technical_failed_item error; all preservation/history checks pass.
- `recovery_result.json`, `resume_result.json`: official service/CLI recovery and original-campaign resume outputs.
- `bounded_live_work.txt`, `after_bounded_live.json`: one real recovery job, three paced/budgeted requests, source4711963 persisted needs_review, audits pass.
- `actors.json`: exact detached actor PIDs, commands, start time and logs.
- `live_progress.json`, `final_live_audit.json`: fresh autonomous persistence and whole-baseline/history checks after startup. Counts are timestamped observations, not final campaign totals.
- `final_preservation_audit.json`: final14:49:46consistent read-only snapshot, including full-row SHA checks for all43old failed jobs and exact preservation of both terminal review rows. `final_summary.json` provides compact completion metrics.

All old P0–P6 evidence and stopped campaigns remain unchanged. Failed-job rows are retained. No raw archive or AI worktree/database was accessed. Audit:

```bash
php docs/collection_30000/ocr_recovery_20261102/inspect.php
```

It connects only to `mening_fikrim_active`, opens a repeatable-read/read-only snapshot, performs no source traffic and never modifies database state. Do not replace baseline.json or past snapshots with new observations; write a new dated file.
