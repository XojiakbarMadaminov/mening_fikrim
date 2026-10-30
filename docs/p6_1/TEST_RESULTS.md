# P6.1 validation evidence

- Initial unchanged baseline suite:201tests/860assertions PASS (`baseline_tests.txt`,`baseline_junit.xml`).
- Focused autonomy/reporting iteration:20tests/142assertions PASS; later terminal-owner and security tests are included in the final complete suite.
- Reporting focused suite:10tests/64assertions PASS (`reporting_tests.txt`).
- Final complete Laravel suite: **225tests /1053assertions /0failures /0errors PASS**,91.150seconds (`full_tests.txt`,`full_junit.xml`).
- Laravel Pint PASS (`pint.txt`); PHP changes use the fixed isolated test database. No default test contacts Lex.uz.
- Main PostgreSQL preservation audit:errors[]; old1079documents/3104identifiers/2763contents,2471verified/292review,24failedjobs preserved. P6 rows/checkpoints/evidence hashes unchanged (`baseline.json`,`preservation.json`).
- Prepared P6.1:paused/prepared=true,0HTTP,0selected,0new canonical. Repeated prepare is idempotent; dry-run makes no source requests (`prepared_campaign.json`,`prepared_repeat.json`,`dry_run.json`).
- Schema introspection:16actual tables/11applied migrations; no new migration/table/column (`schema.json`,`migrations.txt`).

## Meaningful automated coverage

Autonomous synthetic fixture pipeline proves discovery/empty-queue handling, bounded dispatch, automatic stages, review persistence and durable final report. A real controller PHP subprocess plus real worker PHP subprocess complete a two-document/two-stage fixture campaign without the parent advancing stages. Both subprocesses prohibit stray HTTP and use only mening_fikrim_active_test. This is offline integration evidence, not a150-document live result.

Real SIGTERM on a controller pauses at the safe boundary and preserves queued receipt IDs/payloads. Real SIGKILL on a worker releases coordination ownership without refunding a spent token. Additional tests cover live receipt retry_after protection, resumed controller identity/idempotency, duplicate actor rejection, first-start versus later-resume window behavior, terminal operator stop, stale terminal-owner final-report recovery and refusal to follow an access-denied predecessor. Existing P6 SIGKILL/cache-clear/concurrent-PG-session/stale-generation/HTTP budget/discovery tests continue passing.

Reporting tests verify actual counts/languages/quality, timestamps, existing-versus-new distinction, unavailable timing, raw-retention mode, valid/invalid/unconfigured authentication, authorization403, GET-only methods, unknown tool/SQL/control/file/shell rejection, bounded diagnostics, missing campaign404, Uzbek grounded answers without a model and generic503 without credentials/SQL/debug trace leakage. PostgreSQL SQLSTATE25006 proves accidental writes inside the read-only snapshot are rejected and data survives.

## Issues found and corrected

- JSONB object-key order made strict PHP scope comparison reject repeated prepare. Comparison now preserves list order and values while accepting JSONB object ordering; budgets/stages/target are still checked.
- Test environments intentionally disable Artisan signal traps. The isolated signal harness explicitly enables the actual handler; real SIGTERM now tests the production path rather than accepting143as graceful cleanup.
- The inherited foundation assertion allowed only /up before API authorization. It now asserts the exact nine newly authorized report routes plus /up, each API route GET/HEAD with auth and gate. Existing scheduling/timing guards remain; assertions were strengthened, not removed.
- Nested Laravel workers replace signal handlers. The campaign worker restores outer handlers after each bounded job.
- A registered terminal owner can be dead. Final reporting checks the actual PostgreSQL advisory session rather than waiting forever for a stale token.

Tmux is optional and was not installed; documented tmux commands were not executed. No Supervisor/systemd, deployment, source HTTP,150-document import or new LLM/OCR integration was run. Original P0–P6 source/evidence files remain unchanged; the full Laravel suite preserves their existing fixture/parser tests. Historical standalone Python suites were not rerun unnecessarily for this PHP-only stage.

Final snapshot regression additionally verifies explicit absence before termination, CLI/API/tool retrieval after termination and immutable first-snapshot retention on repeated finalization.
