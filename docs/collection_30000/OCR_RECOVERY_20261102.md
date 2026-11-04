# OCR dependency and bounded retry recovery — 2026-11-02

This work uses only `/home/xojiakbar/PhpstormProjects/mening_fikrim`, branch `master`, initial HEAD `fa5845c`, and MAIN `mening_fikrim_active`. Tests use `mening_fikrim_active_test`. The AI worktree, AI database and AI chunking were not accessed or modified. No merge, reset, migration, table addition, data deletion, campaign replacement or scope expansion was performed. Existing staged/uncommitted changes, including historical P0/P0.1 bytecode files, were retained.

## Confirmed starting state

The immutable [baseline](ocr_recovery_20261102/baseline.json), captured at 14:28:35 Asia/Tashkent, confirms the same campaign `corpus-30000-20261030` stopped at 12:34:53 with `stage_integrity_audit_failed`.

| Metric | Before recovery |
|---|---:|
| Campaign NEW / target | 6,385 / 30,000 |
| Documents | 10,052 |
| Identifiers | 31,551 |
| Contents | 25,727 |
| Verified / needs_review | 19,520 / 6,207 |
| Runs / items | 699 / 16,428 |
| Failed jobs / pending jobs | 43 / 0 |
| Campaign HTTP / cap | 50,878 / 360,000 |
| Selected source IDs / cap | 10,832 / 75,000 |

The starting read-only audit found only `technical_failed_item`; old document/content/identifier preservation and all prior checkpoint/campaign hashes passed. There were 177 completed discovery root intervals and no unfinished root interval. This is bounded campaign coverage, not global Lex.uz completeness.

Failed-job classification was established from actual exception and payload evidence:

- IDs 1–24: historical P2 parser/quality-review exceptions, unrelated to this OCR incident.
- ID 25: historical HTTP 404 already acknowledged by the earlier explicit recovery.
- IDs 26–43: 18 missing-Tesseract process errors, exit 127, all run 699 of this campaign.
- No additional database/queue error class was identified among these 43 rows.

Affected items: 16420 / `4711963` failed after 24 cumulative attempts; 16427 / `4765003` review after 13; 16428 / `4765001` review after 21. The latter two have unknown source legal-status metadata. Their completed review outcomes must remain terminal; repairing OCR does not authorize guessing their status.

## Root causes

The local `.env` and `.env.testing` still referenced the P0 spike's disposable `/tmp/lex-p0-20261028-fLI3Zm` Tesseract, libraries and language models. Cleanup removed that directory. `PdfTextExtractor` invokes native Tesseract through Symfony Process; OCR does not require Python. The separately enabled optional pdfplumber layout diagnostic also referenced a deleted temporary virtual environment. That explains the initial test-suite environment error.

The retry storm had an independent code cause. Laravel permits three attempts per queue receipt, but the controller's pending-run resume path called `RunItemTracker::recover()`, which also selected terminal `failed` items. Each redispatch created a new queue receipt with fresh Laravel attempts. Durable item attempts were incremented but were not enforced as a limit. Thus individual receipts were bounded while a source could accumulate 24 attempts and multiple failed jobs.

## Permanent dependencies and preflight

`sudo -n` requires an operator password. A system-wide apt installation was therefore **not** performed or claimed. Official Ubuntu Noble packages were downloaded with `apt-get download` and extracted with `dpkg-deb` into the persistent user-owned prefix:

`/home/xojiakbar/.local/share/mening-fikrim/ocr/runtime`

Tesseract 5.3.4, libtesseract 5.3.4, Leptonica 1.82.0 and the existing eng/rus/uzb/uzb_cyrl/osd models are installed there. Package versions and SHA256 receipts are in [dependencies.json](ocr_recovery_20261102/dependencies.json). They survive `/tmp` cleanup and ordinary reboot; they are not registered as system packages and require deliberate future maintenance.

The existing optional layout dependency was restored using the installed `uv` and the project's existing `requirements-parser.txt`, without adding an extraction technology. Its persistent environment is `/home/xojiakbar/.local/share/mening-fikrim/pdf-layout-venv`, pdfplumber 0.11.10. The first standard `python3 -m venv` attempt failed because system ensurepip was unavailable; the subsequent `uv venv --seed` installation succeeded. The package manifest records the actual installed Python dependencies.

Only parser path settings in MAIN's ignored `.env` and `.env.testing` changed:

```dotenv
SCRAPING_TESSERACT_BINARY=/home/xojiakbar/.local/share/mening-fikrim/ocr/runtime/usr/bin/tesseract
SCRAPING_TESSDATA_PREFIX=/home/xojiakbar/.local/share/mening-fikrim/ocr/runtime/usr/share/tesseract-ocr/5/tessdata
SCRAPING_PROCESS_LIBRARY_PATH=/home/xojiakbar/.local/share/mening-fikrim/ocr/runtime/usr/lib/x86_64-linux-gnu
SCRAPING_PYTHON_BINARY=/home/xojiakbar/.local/share/mening-fikrim/pdf-layout-venv/bin/python
SCRAPING_PDFINFO_BINARY=/usr/bin/pdfinfo
SCRAPING_PDFTOTEXT_BINARY=/usr/bin/pdftotext
SCRAPING_PDFTOHTML_BINARY=/usr/bin/pdftohtml
SCRAPING_PDFTOPPM_BINARY=/usr/bin/pdftoppm
```

Database credentials, live defaults, pacing, scope and quality settings were not changed. `config:clear` was used; application cache/budgets were not cleared.

New command:

```bash
php artisan documents:health --json
```

It performs no Lex.uz traffic and no writes. It checks executable existence/permission, rejects temporary executable paths, executes version commands, verifies required language models and configured directories, checks all four Poppler binaries, and checks Python/pdfplumber only when the existing optional layout feature is enabled. Failure returns nonzero with `OCR_DEPENDENCY_MISSING` and structured issue codes. Campaign worker/controller, network probe and bounded work now preflight dependencies; native OCR checks them again before invoking OCR. Missing native process/loader/model diagnostics become typed permanent dependency errors.

## Retry and dispatch policy

The durable item's cumulative attempt count now gates both claim and enqueue. The normal limit is at most three attempts, respecting a lower configured retry limit. Replacing a lost/stale queue receipt does not replenish it. Ordinary run recovery excludes terminal failed/review/imported/unchanged/alias items. A stale receipt whose cumulative budget is exhausted becomes terminal `SOURCE_RETRY_LIMIT_EXCEEDED`.

Missing dependency fails once with `permanent_dependency` / `OCR_DEPENDENCY_MISSING`, records the item failure, and stops the campaign `runtime_dependency_unavailable`. Native process exit failures are classified `permanent_extraction` except interrupted/killed 137/143. Transient exceptions, including OCR timeout, retain the existing bounded backoff/retry behavior. Quality review remains a normal terminal diagnostic, not a queue exception. No validator, identity gate or audit was weakened.

Explicit audited OCR recovery establishes a new bounded source retry epoch in existing outcome JSONB: `base_attempts=24`, `limit=3`, operator epoch 4. The cumulative 24 attempts are never reset. A maximum of three explicit source-recovery epochs and ten campaign operator recoveries is enforced. This is a deliberate operator action after repair, not automatic retry of terminal work.

## Recovery performed

```bash
php artisan documents:campaign recover-ocr \
  --id=corpus-30000-20261030 \
  --failed-jobs=26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43
php artisan documents:campaign resume --id=corpus-30000-20261030
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign work \
  --id=corpus-30000-20261030 --max-jobs=1
```

These are historical executed commands, **not commands to repeat blindly**. The recovery command is intentionally narrow: exact latest campaign, stopped OCR/integrity reason, no live owner or pending queue, remaining original time/request/selection budgets, no recorded 403/429, preserved terminal snapshot, and explicit matching same-campaign missing-Tesseract exception receipts. No unserialization of failed payloads is needed. Full integrity must pass within the transaction.

All 18 failed-job rows remain unchanged, with exact SHA256 acknowledgements in campaign state. `operator-recovery-4` records previous campaign/item state, dependency health, selected receipt evidence and passing audit. Original `final-report`, `final-report-1`, `final-report-2`, `final-report-3` remain unchanged; a subsequent terminal snapshot uses `final-report-4`.

Only item 16420 was requeued. The two completed review sources were not fetched or reset. Recovery leaves the same campaign paused until explicit resume. Its original start 2026-10-30 10:04:00 +05, 21-day deadline, target, selected IDs, scope, HTTP counters and limits were retained. There was no direct SQL state forgery or mass `queue:retry`.

## Bounded real OCR verification

The single recovery job fetched real `4711963` through the existing globally paced and budgeted client: three HTTP requests. Its two-page PDF used OCR on page 2, model `uzb_cyrl+uzb`, and produced usable Uzbek Cyrillic content. PDF parsing took 3.353331 seconds, of which OCR took 2.010153 seconds. Low confidence, uncertified OCR, reading-order, structure, image, critical-token and language-coverage warnings were retained. It was persisted as needs_review, not promoted to verified.

Result: new document 10053; campaign 6,386 NEW; contents 25,728; review contents 6,208; HTTP 50,881; failed jobs unchanged at 43; cumulative source attempts 25. The post-job full preservation/duplicate/orphan/nonempty/content-hash/archive audit returned `errors=[]`, and all original checkpoint/historical campaign hashes matched. [Bounded live evidence](ocr_recovery_20261102/after_bounded_live.json).

A separately labelled synthetic image-PDF also exercised the same actual native OCR pipeline offline: one page, 1,820 Markdown characters, 4.249767 seconds PDF extraction / 3.714457 seconds OCR. It is explicitly not a Lex.uz source or accuracy certification. [Synthetic evidence](ocr_recovery_20261102/synthetic_ocr.json).

## Tests and formatting

Initial suite: 256 tests, 1,229 assertions, one error caused by the missing optional Python interpreter in the archived-PDF test. After restoring the existing dependency and implementing changes: **267 tests, 1,315 assertions PASS**, 113.414 seconds, 115 MB. Tests used only the guarded test database and prevented real HTTP. [JUnit](ocr_recovery_20261102/final_junit.xml), [console output](ocr_recovery_20261102/final_tests.txt).

Regression coverage includes ready/missing/non-executable/temporary Tesseract, failed executable, missing language model, optional Python, startup rejection, permanent dependency failure once, transient OCR timeout/backoff then success, cumulative retry exhaustion, stale receipt fencing, no terminal redispatch, audited recovery preserving budgets/checkpoints/content/failed rows, completed review preservation, idempotent existing-document import, and rollback when an unacknowledged failure remains. Existing identity/quality/discovery/access protections continue to pass.

Pint passes for all changed application/test PHP files and the new read-only audit script. Whole-repository `vendor/bin/pint --test` reports one **pre-existing** formatting issue in `docs/p3/content_audit.php` (`method_argument_space`). That historical P3 evidence file matches HEAD and was deliberately left unchanged. [Whole-repo result](ocr_recovery_20261102/pint.txt), [changed files](ocr_recovery_20261102/pint_changed_files.txt).

## Running and monitoring

After the bounded success and clean audit, the existing detached actors started at **14:44:06 +05**: worker PID **207663**, controller PID **207665**. They run in separate sessions with stdin disconnected and append to existing logs, so terminal/Codex closure does not terminate them. There is no claimed Supervisor/systemd service or automatic reboot recovery.

Read-only commands from MAIN:

```bash
php artisan documents:health --json
php artisan documents:campaign status --id=corpus-30000-20261030 --json
php artisan documents:campaign report --id=corpus-30000-20261030 --format=markdown
php docs/collection_30000/ocr_recovery_20261102/inspect.php
tail -n 30 storage/logs/corpus-30000-worker.log
tail -n 30 storage/logs/corpus-30000-controller.log
ps -p 207663,207665 -o pid,etime,stat,args
```

The audit script is restricted to MAIN and reads a repeatable-read, read-only snapshot. It verifies the entire task baseline, all original checkpoint hashes and previous campaign rows, classifies failures, and reports current counters/last document. It does not repair data or issue source traffic.

If actors exit after host/PostgreSQL shutdown, first check health, status, receipts and stop reason. For an eligible **running** campaign with no existing actors, use the same two commands in separate persistent terminals/process sessions:

```bash
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign worker --id=corpus-30000-20261030
SCRAPING_LIVE_ENABLED=true php artisan documents:campaign run --id=corpus-30000-20261030
```

If recoverably paused, explicit `documents:campaign resume --id=corpus-30000-20261030` precedes those commands. Terminal safety stops require diagnosis and their applicable explicitly authorized recovery; do not loop recovery, reset counters, duplicate workers, retry all 43 failures or create another campaign. Graceful pause remains `documents:campaign pause --id=corpus-30000-20261030`.

## Changed implementation files

- `app/Services/Scraping/ScrapingDependencyHealth.php`, `ScrapingDependencyException.php`: native dependency checks and typed permanent error.
- `app/Console/Commands/ScrapingHealth.php`, `ManageCampaign.php`: offline health command, live preflight and explicit recover-ocr CLI.
- `app/Scraping/LexUz/PdfTextExtractor.php`: native OCR preflight and missing-process classification.
- `app/Services/Scraping/RunItemTracker.php`: cumulative attempt guard, terminal recovery exclusion and explicit retry epoch.
- `app/Jobs/ImportDocumentJob.php`: permanent/exhausted classification, one failed receipt and dependency stop.
- `app/Services/Scraping/DurableCampaign.php`: guarded transactional OCR recovery with immutable evidence.
- `tests/Feature/ScrapingDependencyHealthTest.php`, `DurableTrackingTest.php`, `CampaignRecoveryTest.php`: offline regressions.
- MAIN ignored `.env`, `.env.testing`: persistent parser paths only.
- This report, its evidence/read-only audit, current collection README, PROJECT_CONTEXT and TASKS updates. No migrations.

## Remaining limitations

OCR fidelity is still uncertain; successful extraction is not legal certification. The two completed review sources with unknown metadata remain unresolved. No raw source archive was enabled. User-prefix packages/venv need future maintenance; machine/DB/network uptime is still required and reboot needs an operator restart. Existing access, repeated transport/server, identity, integrity, time, selection and HTTP-budget stop conditions remain terminal. One worker, >=2,000 ms pacing, <=30 selections/run and <=400 requests/context remain unchanged. No new OCR/PDF technology, AI feature, scheduling, deployment or full import was introduced.

## Final observed live progress — 14:49:46 +05

The [final preservation audit](ocr_recovery_20261102/final_preservation_audit.json) uses a consistent read-only database snapshot. Same campaign status is **running**, controller phase `wait`, run700, scope index178. Both original detached actor PIDs remain alive. No new technical failed job occurred.

| Metric | Before | Final observation | Change |
|---|---:|---:|---:|
| Campaign NEW | 6,385 | 6,407 | +22 |
| Documents | 10,052 | 10,074 | +22 |
| Identifiers | 31,551 | 31,615 | +64 |
| Contents | 25,727 | 25,790 | +63 |
| Verified contents | 19,520 | 19,582 | +62 |
| Needs-review contents | 6,207 | 6,208 | +1 |
| Failed jobs | 43 | 43 | 0 |
| Runs / items | 699 / 16,428 | 700 / 16,458 | +1 / +30 |
| HTTP used | 50,878 | 51,024 | +146 |
| Source selections | 10,832 | 10,862 | +30 |

Remaining target: **23,593 NEW**. Remaining HTTP: **308,976 / 360,000**; selection cap remains75,000; original21-day execution window remains unchanged. Five receipts were outstanding in the observed bounded run. Last stored document was10074, canonical external ID`-5254988`, created14:49:30+05. Discovery advanced without replacing any old checkpoint; all193 original checkpoint hashes and all four prior campaign hashes match.

The recovery contributed one real OCR document; the autonomous actors contributed21 more new canonical documents, so recovery was verified beyond merely starting processes. That small autonomous observation is approximately223NEW/hour over339seconds of actor wall-clock, including pacing, discovery, queue coordination and audit overhead. It is a short sample, not a guaranteed sustained throughput or completion estimate. The146 additional requests include the three-request recovery and143 subsequent budgeted requests.

Final integrity errors and history errors are both empty. All10,052original document identities,31,551identifiers,25,727content IDs/hashes/quality values, all43original failed-job full-row hashes, and both completed review item rows are preserved. Existing verified content has not been downgraded. No duplicate, orphan, empty/hash-mismatched content or unsafe persisted identity was found. OCR source4711963 remains review with its diagnostics and attempt25; it is not being repeatedly redispatched.

The parser is left running under the existing target and guards. Completion of this repair means restored, tested and observed collection; it does not claim completion of the30,000target.
