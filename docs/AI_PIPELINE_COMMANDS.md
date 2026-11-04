# Isolated AI pipeline commands

Run from `/home/xojiakbar/.codex/worktrees/ai-legal-chunking/mening_fikrim` on `feature/ai-legal-chunking`. The guarded database is `mening_fikrim_ai_dev`; automated tests exclusively use `mening_fikrim_active_test`. Never run normal migrations or parser workers in AI DEV. Existing MAIN/parser actors are independent.

## Implemented order

```bash
php artisan legal:migrate
php artisan legal:chunk-documents --status
php artisan legal:chunk-documents --dry-run --content-limit=100
php artisan legal:chunk-documents --content-id=123
php artisan legal:chunk-documents --only-pending --content-limit=100 --limit=500 --queue
php artisan legal:work --max-jobs=300 --rest-ms=10
php artisan legal:search 'fuqarolar' --language=uz-Latn --quality=eligible --limit=10 --page=1
```

`--limit` counts distinct **documents**, while `--content-limit` bounds language/content representations. `--content-id` and `--document-id` may repeat; the existing size/force/queue/status options remain available. Chunking includes stored translations; `--language` is a search filter, not a chunk-command option. Status reports source v2 and retrieval v3 separately. A completed existing input is reused; `--only-pending` selects new/changed/missing-profile inputs. A new source gets its exact source generation before its retrieval generation. Dry-run writes neither layer nor queue. P3 force retries a failed/exhausted input but preserves successful source and passage history. Change the algorithm version/profile to create a different successful output.

Dispatch is explicit and bounded, not automatic scheduling. The queue is `pgsql_ai.ai_jobs`, connection `ai_database`, queue `legal-chunking`; jobs pin version/profile. `legal:work` is allowlisted to the chunk job, uses an independent advisory mutex, exits when empty/at its delivery limit and does not store failures in MAIN. Source generation failures and retrieval generation failures have separate ledger/status diagnostics. Matching failures are capped at three attempts; historical failures for an old fingerprint do not count as current failed inputs.

## P3 acceptance coordinator

The recorded stages belong to the fixed existing snapshot, not a parser campaign. Do not rerun P1/P2 scripts to overwrite historical evidence.

```bash
php scripts/ai_p3_sample.php
# Exactly 100 diverse, recorded source IDs; refuses to replace the sample.
taskset -c 19 nice -n 10 php -d memory_limit=384M scripts/ai_p3_collect.php --phase=pilot100
taskset -c 19 nice -n 10 php -d memory_limit=384M scripts/ai_p3_collect.php --phase=pilot1000
taskset -c 19 nice -n 10 php -d memory_limit=384M scripts/ai_p3_gate.php
taskset -c 19 nice -n 10 php -d memory_limit=384M scripts/ai_p3_collect.php --phase=full
```

The full phase requires both exact pilots and a passed gate. It journals at most 100 content IDs before dispatch, starts one low-priority worker (inherited/additional nice gives 19 here; at most 300 deliveries), validates every source, saves the batch and pauses. Worker PHP limit is 384 MiB with a 256 MiB between-job check; CPU 19 is the allowed local CPU used here. Minimum available RAM is 3 GiB and disk is 10 GiB. Use an allowed CPU on another host. Unexpected queue receipts, profile changes or attribution errors cause a stop. No unlimited dispatch or recovery loop exists.

A stopped phase resumes with the same command and recorded profile; it finishes its saved inflight batch before selecting new IDs. Successful matching inputs are idempotent. A completed phase invocation is a no-op. Inspect a failure/receipt rather than clearing evidence or changing algorithms mid-phase. The P3 gates/evidence files refuse overwriting; final scripts run only on a settled full generation.

```bash
taskset -c 19 nice -n 10 php -d memory_limit=384M scripts/ai_p3_validate.php
taskset -c 19 nice -n 10 php -d memory_limit=384M scripts/ai_p3_evaluate.php
vendor/bin/phpunit
vendor/bin/pint --test
```

Validation checks all source/passage identities and exact reassembly, source table/all historical source chunk row hashes, layer uniqueness, length bounds and a real pending-only no-op. Evaluation fixes a real-corpus dataset, verifies exact anchors and records both v2/v3 keyword baselines. Evaluation's v2 toggle is process-local configuration, never a generation switch or database write. Parser observation scripts are fixed read-only audits; they do not operate actors.

## Future only

Conceptual `documents:import → documents:chunk → documents:embed → documents:search` does not replace the parser or add new commands today. There is no `documents:embed`, embedding worker, scheduler, GPU API, vector table or automatic MAIN sync. Embedding jobs will require a distinct identity/ledger/queue and explicit future authorization. See [EMBEDDING_READINESS.md](EMBEDDING_READINESS.md).
