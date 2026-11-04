# AI DEV database — isolated snapshot

2026-11-02 user authorization permits this separate AI development database and deterministic chunking. Historical collection restrictions are superseded only for this isolated task. No embedding/LLM, source collection, parser recovery, production changes or automatic synchronization is included.

## P2 continuation — current authorization

The subsequent P2 task explicitly authorizes chunking all eligible representations in THIS existing snapshot, bounded 100 / additional 1,000 pilots, current-generation keyword retrieval and AI-only migrations. No new MAIN snapshot, restore or synchronization is authorized. P1 counts/evidence below remain historical. Current execution and final results are in [AI_CHUNKING_P2_REPORT.md](AI_CHUNKING_P2_REPORT.md) and [phases.json](ai_p2/phases.json).

AI migrations 000002/000003/000004 add durable current-generation controls, retry diagnostics and a concurrent lexical GIN index covering all qualities. The full index replaces an initial structured-only index after measured review/all-query timeouts. They run only via `legal:migrate` in the isolated AI database. The source copy receives only invalidation triggers; source document rows remain unchanged. This is PostgreSQL full-text keyword indexing, without embedding/vector storage or external search engines.

## Working environment

- Parser checkout: `/home/xojiakbar/PhpstormProjects/mening_fikrim`, branch `master`; left untouched, including seven previously staged Python bytecode files.
- AI checkout: `/home/xojiakbar/.codex/worktrees/ai-legal-chunking/mening_fikrim`, branch `feature/ai-legal-chunking`.
- MAIN: `mening_fikrim_active`; existing Laravel Database Queue/cache/campaigns.
- AI DEV: `mening_fikrim_ai_dev`; explicit `pgsql_ai` connection, separate owner `mening_fikrim_ai_dev_owner`, independent local `.env`, storage/logs and copied vendor tree.
- Regression tests: only `mening_fikrim_active_test`. AI connection is explicitly aliased to that same guarded test database inside the new feature tests. Tests never open MAIN/AI DEV or contact Lex.uz.

The AI owner is NOSUPERUSER/NOCREATEDB/NOCREATEROLE/NOREPLICATION. Read-only privilege introspection confirmed it cannot SELECT, INSERT, UPDATE, DELETE or TRUNCATE MAIN documents or create schemas in MAIN. No MAIN grants/roles/table/schema changes were made. Source snapshot uses the existing local administrative credential in READ ONLY transactions because creating a SELECT-only grant in MAIN would violate this task's prohibition on MAIN changes. Passwords remain in gitignored mode-0600 environment files or subprocess environments, never command arguments or reports.

## Completed snapshot

Snapshot began **2026-11-02 11:44:18 Asia/Tashkent**, verified **11:46:45**. Exactly four source tables were restored:

| Table | Rows |
| --- | ---: |
| document_sources | 2 |
| documents | 10,036 |
| document_identifiers | 31,499 |
| document_contents | 25,691 |

`document_contents.content` is stored Markdown, not retained HTML. Language/content ID/title/hash/quality/extraction metadata/pending candidate remain unchanged. Current source content is unique per `(document_id, language)`; the parser does not retain historical editions as separate source rows. Pending candidates are preserved in the copy but are not chunked as authoritative text.

Dump: `/home/xojiakbar/.local/share/mening-fikrim-ai/snapshot-20261102/corpus.dump` (about 208 MiB), outside both repositories, mode 0600. Its SHA-256 and four ordered complete-row checksums/counts are in [snapshot_manifest.json](ai/snapshot_manifest.json). The restore was checked against the SAME exported source snapshot, not a later moving count. It is a point-in-time copy, not an automatically synchronized replica.

`pg_dump --format=custom --snapshot=...` runs while an exported REPEATABLE READ / READ ONLY transaction stays open. It takes normal ACCESS SHARE locks, compatible with ordinary parser inserts/updates; a five-second lock-wait timeout refuses a conflicting DDL lock. No pause, reset, user-table lock mode change, source request or source write is performed. The exported transaction is released after dump, before restore.

Queue, jobs, failed_jobs, cache, scraper runs/items/campaigns/checkpoints and archive tables were excluded entirely. Consequently no copied operational work can execute. AI infrastructure is created separately; original 25 failed jobs remain in MAIN.

## Snapshot and restore procedure

Already completed here: **do not rerun provisioning over this database**. On another fresh local environment, use an isolated checkout with vendor dependencies and NO existing `.env`, AI database or AI owner. Choose a new output directory outside Git, then:

```bash
php scripts/ai_snapshot.php \
  --source-env=/absolute/path/to/parser/.env \
  --output=/absolute/private/path/to/new-snapshot-directory
```

The standalone script never boots the parser application. It checks the fixed MAIN source name/no DB URL, canonical output path, existing target/role and existing worktree `.env`; exports the read-only snapshot; dumps only the four verified corpus tables; creates the NEW role/database via an administrative connection to `postgres`; restores using the AI role with `--single-transaction --exit-on-error --no-owner --no-privileges`; checks actual target name and every restored row checksum; and writes a private AI-only `.env`.

It never uses `--clean`, drops a database/role, clears an existing target or restores over existing data. Any failure stops with nonzero exit. A partially provisioned new role/database is retained for explicit inspection, never silently cleaned/replaced. The original database is unaffected. Back up an existing AI database separately before intentionally replacing anything; no replacement workflow is implemented.

## Configuration and migrations

[.env.ai.example](../.env.ai.example) is a secret-free reference. Credentials for both default and explicit AI connection select the AI owner/database; `.env` contains no MAIN credential. `AI_DB_CONNECTION=pgsql_ai`, `AI_DB_DATABASE=mening_fikrim_ai_dev`, `AI_DEV_ENABLED=true`. No DB URL/read-write overrides are accepted. Production environment is refused. Both the configured database and `SELECT current_database(), current_schema()` must match before any legal command/model/migration can write. The test-only exception requires Laravel's actual testing environment and the exact dedicated test database.

From the **AI checkout only**:

```bash
php artisan legal:migrate
php artisan legal:chunk-documents --status
```

AI migration path: `database/ai-migrations`; it is absent from normal Laravel migration discovery. The migration declares `pgsql_ai` and calls the guard before schema operations, including rollback. `legal:migrate` explicitly supplies path/connection. In AI mode ordinary migrate/reset/refresh/fresh/rollback commands are blocked; do not use them. MAIN has its original 13 migrations and 16 tables, with no AI table.

AI migration `2026_11_02_000001_create_legal_chunks` adds `document_chunk_sets`, `document_chunks` and `ai_jobs`, plus an AI-copy-only source composite UNIQUE used for translation-safe foreign keys. The new AI `migrations` repository contains only AI migrations. See [LEGAL_CHUNKING.md](LEGAL_CHUNKING.md) for schema/commands.

## Queue and source isolation

AI default queue `ai_database` targets `pgsql_ai.ai_jobs`, queue `legal-chunking`; cache/session use array stores. No Redis/Horizon or scheduler is added. Parser `documents:*`, scheduler and parser queue commands are rejected in AI mode. Queue payload execution is allowlisted to `ChunkLegalDocumentJob`; source HTTP through the existing Laravel client is blocked in AI mode. No AI parser worker was launched.

Use the bounded worker wrapper, which pins AI database/queue, disables failed-job database logging and exits when empty or after the specified maximum:

```bash
php artisan legal:chunk-documents --only-pending --limit=20 --queue
php artisan legal:work --max-jobs=50
```

Exhausted jobs log content ID/exception class in the isolated log. Failed or interrupted content remains pending; rerun bounded `--only-pending` to dispatch it again. No MAIN failed-job storage, queue restart, retry or campaign manipulation occurs.

## Verification and current parser observation

[verification.json](ai/verification.json) records 15 actual documents / 19 source representations / 2,295 chunks, zero processing or reference/coverage errors, identical repeated-run rows, and unchanged full source table checksums. This is the explicitly requested bounded acceptance run in AI DEV, distinct from destructive automated regression tests. [security_verification.json](ai/security_verification.json) records MAIN privilege checks; [main_audit.json](ai/main_audit.json) records read-only source schema/counts/campaign state.

Historical P1 observation (the operator restarted the parser before P2; P2 observes it read-only): the parser was already stopped when P1 inspected it: `corpus-30000-20261030`, 6,369 NEW, 50,479 spent HTTP, reason `source_http_0_repeated`; no matching actor processes were present. No stop/restart/recovery or campaign write was made in this task. Restart/recovery requires a separate operator instruction under existing collection safeguards.

## Full-corpus continuation and recovery

Use only the existing AI checkout. Exact content limits are now available alongside the original document limit:

```bash
php artisan legal:chunk-documents --status
php artisan legal:chunk-documents --only-pending --content-limit=100 --limit=500 --queue
php artisan legal:work --max-jobs=300 --rest-ms=10
```

The P2 coordinator records each selected source ID list BEFORE dispatch, runs one independent worker, verifies every source after each bounded batch, and saves progress. Stages `pilot100`, `pilot1000`, `full` are gated and resumable, never unlimited dispatch. Resume the recorded full phase:

```bash
taskset -c 19 nice -n 10 php -d memory_limit=384M scripts/ai_p2_collect.php --phase=full
```

CPU 19 is the verified local CPU affinity used for this run; choose an allowed CPU when moving hosts. Workers inherit one-core affinity, nice 10, low I/O priority, a 384 MiB PHP limit and a 256 MiB between-job worker check. Each dispatch has at most 100 source representations; workers have at most 300 deliveries including retries and pause 10ms between jobs; batches pause 250ms. Only one AI coordinator/worker can acquire their separate AI advisory mutexes. No parser mutex, queue/cache or actor is accessed.

After interruption, a saved inflight batch is completed before new dispatch; valid completed inputs are skipped. Unexpected unrelated AI receipts cause a safe stop for inspection. Transaction rollback and database queue reservation/retry rules retain unfinished work. A matching failed generation gets at most three attempts; changed input receives a new generation, and `--force` is explicit. The completed phase command is a no-op. Source counts remain the fixed snapshot, while MAIN independently grows.


## P3 continuation — isolated two-layer preparation

P3 explicitly authorizes deterministic universal chunking, bounded retrieval passages, diverse 100 / additional 1,000 pilots followed by gated processing of this existing fixed snapshot, and offline keyword evaluation. No restore, new snapshot or MAIN synchronization occurred. Historical P1/P2 evidence above is preserved. See [AI_CHUNKING_P3_REPORT.md](AI_CHUNKING_P3_REPORT.md) for actual P3 results.

AI migration 000005 adds `document_chunk_sets.layer`, a source-generation FK and one `retrieval_passages` table with exact byte/character spans, hash, original chunk lineage and separate quality axes. One current set per `(content_id,layer)` keeps immutable v2 source and current v3 retrieval independent. Source rows and old `document_chunks` rows are unchanged. Migration 000006 adds one concurrent simple-lexeme GIN on passage bodies after the real 28,147-passage pilot measured approximately 4.9-second scans. The old source GIN remains for history/comparison; no duplicate index of the same expression on the same table was added.

Use the P3 coordinator and commands in [AI_PIPELINE_COMMANDS.md](AI_PIPELINE_COMMANDS.md). P2 scripts/output directories are historical; do not use them for P3 validation. Source invalidation affects both current layers, while source mutation/metadata fingerprint changes select only the affected input. Automated regression tests still exclusively use `mening_fikrim_active_test`; acceptance uses the isolated AI copy. No new default/parser migrations or operational tables were added.
