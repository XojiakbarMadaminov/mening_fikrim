# Deterministic legal document chunking

The engine works on the isolated AI DEV snapshot of stored Markdown. It never fetches Lex.uz, edits source text, invokes an LLM/OCR engine or creates embeddings. Use the AI worktree and configuration described in [AI_DATABASE.md](AI_DATABASE.md).

## P2 current behavior

P2 uses `legal-v2`; `legal-v1` generation/chunk rows remain as historical data. The 100-content pilot and additional 1,000-content pilot verify byte coverage before the full snapshot run. See [AI_CHUNKING_P2_REPORT.md](AI_CHUNKING_P2_REPORT.md) for actual completion/quality metrics.

Version changes are deliberate: named UMUMIY/MAXSUS QISM / Cyrillic / Russian parts are recognized without invented numbers; annex/title boundaries clear obsolete document hierarchy; unstructured fallback is now `needs_review`, with explicit reasons. OCR/source review is never promoted. Source length is measured once per source instead of once per chunk; writes use at most 100 chunk rows per insert inside the same per-source transaction.

Configuration includes target `chunk_size`, review threshold `max_chunk_size` (default 8,000), `structure_splitting`, explicit zero `overlap_chars`, version and max attempts (three). These policy inputs participate in profile identity. Maximum is a review threshold, not permission to truncate: indivisible long sentences/tables remain exact, with oversized/maximum-length review reasons. No tokenizer/model fit is asserted.

`document_chunk_sets` now has `is_current`, attempts, last error class and last attempt timestamp. Its unique key includes source content, profile and complete input fingerprint; changed source editions receive NEW rows instead of deleting prior chunks. A partial unique index permits one current generation per source. Successful activation and deactivation of the old generation occur atomically under source+document locks. AI-only source/content URL/quality/metadata changes invalidate current flags through triggers. Failed generations never replace a working current generation.

`ChunkDocumentService::currentChunks()` is the single retrieval selection: desired policy profile, completed/current set, matching source checksum, language/document/content/version identities. Old profiles/editions cannot leak into keyword results. Queued jobs pin their policy hash/version and refuse silent algorithm drift. The existing `--force` replaces only the explicitly selected exact generation; it does not clear historical editions.

New options: repeatable `--content-id` and `--content-limit=1..500`. Original `--limit` STILL counts distinct documents and includes their stored languages. `--status` now reports current/pending/failed/retry-exhausted/source-review counts, versions and both current/all chunk totals. Pending means no current matching generation and includes unresolved failed sources. The corpus coordinator uses exact lists of at most 100 representations per batch.

```bash
php artisan legal:chunk-documents --only-pending --content-limit=100 --limit=500 --queue
php artisan legal:work --max-jobs=300 --rest-ms=10
php artisan legal:search mehnat --language=uz-Latn --quality=structured --limit=10
php artisan legal:search меҳнат --language=uz-Cyrl --quality=all --page=1
php artisan legal:search труд --language=ru --category=Кодекс
```

Keyword retrieval uses PostgreSQL `simple` lexemes with bound `plainto_tsquery` parameters, exact language/category/quality filters and stable chunk-ID order. It returns document title/type/legal status, actual nullable source URL, source content/chunk IDs/order/hierarchy/hash/checksum/version/quality and excerpt. Default quality is `structured`; `needs_review` and `all` are explicit. Pages are 1..1000, results 1..50, query 2..200 UTF-8 characters; one query has a five-second PostgreSQL timeout. It is lexical matching without Uzbek/Russian stemming, transliteration, semantic relevance ranking or legal applicability judgment. Legal status is attributed; callers still need a legal-status/date policy before legal RAG.

The AI-only GIN expression index covers all chunk qualities. Migration 000003 first indexed structured chunks; full-corpus review/all queries reached their five-second budget, so migration 000004 replaced that partial index with an all-quality lexical index after a real oversized-text preflight. Lexical matches are materialized, candidate IDs are filtered through the current ledger, and page metadata is hydrated through all source-identity guards in the same repeatable-read/read-only snapshot. This avoids measured wide-row/LIMIT and redundant-checksum join plan failures. Needs-review/all queries can scan larger text and may reach the time budget; narrow language/category/terms. No pgvector/embedding index, external engine or new provider is used. [search_index_comparison.json](ai_p2/search_index_comparison.json) records measured plans/results on one fixed read-only snapshot.

`LegalChunkVerifier` independently verifies every byte offset, ordered text, chunk/source hash/reference, generation fingerprint and source quality non-promotion. `scripts/ai_p2_validate.php` checks all current representations and source-table snapshot hashes with bounded reads, duplicates and a repeated pending command. `scripts/ai_p2_review.php` generates boundary packets for operator review; no human/manual_verified status is fabricated. P1 `scripts/verify_legal_chunks.php` now refuses to overwrite frozen P1 evidence.

## Algorithm and fidelity

`DocumentStructureParser` recognizes anchored Latin Uzbek, Cyrillic Uzbek and Russian headings, using actual stored Lex.uz Markdown: `I BOʻLIM`, `I БЎЛИМ`, `Раздел I`, `1-bob`, `I боб`, `Глава 1`, `1-modda`, `1-модда`, `Статья 1`, numeric parts/points, letter/number subpoints and annexes. Arabic/Roman numbers and observed Unicode superscript article suffixes are retained. Markdown h1 supplies document title; unknown Markdown headings retain their text/type without invented numbers. Original HTML is not present in the authoritative corpus, so this version uses its already preserved Markdown heading structure.

One article stays together when within the target. Large articles split first at observed part/point/subpoint boundaries and paragraph breaks, then at sentence punctuation. Heading context (section/chapter/article), subdivision context and boundary byte offsets remain attached to splits. Chapter/annex changes reset stale article context. Ordinary unstructured text uses paragraph/sentence fallback. Text is never trimmed, normalized, rewritten, translated, summarized or repaired. No synthetic article numbers are assigned.

OCR review evidence or replacement characters disables inferred legal headings for conservative paragraph/sentence fallback. Non-OCR PDF text may use explicitly observed headings but retains source `needs_review` status. This does not certify legal correctness, reading order, images or OCR quality. Short/uncertain/oversized text is `needs_review`; historical v1 unstructured usable text was `fallback`; v2 uses `needs_review` with `structure_unrecognized`; reliable recognized structure is `structured`. These describe chunking quality, not a new legal active/inactive status or human certification.

`AI_CHUNK_SIZE` defaults to 4,000 Unicode characters, accepted range 200..32,000. This is a target: an indivisible oversized sentence/table remains complete with `oversize=true` and review status. Simple Markdown table runs are not split into arbitrary character spans; complex preexisting table semantics still require review. No fixed-character slicing occurs. This version uses zero overlap, explicitly recorded as `overlap_bytes=0`; all text occurs once. Tokens use the labelled **heuristic** `ceil(characters/4)`, not a model tokenizer or a context-limit guarantee.

Every chunk carries `[start_byte,end_byte)` offsets into the exact stored UTF-8 source, content SHA-256, language, source checksum and provenance URL. Reassembly in index order is checked for byte-for-byte equality BEFORE persistence. The bounded real verification independently checks every offset/hash/index/source identity and complete reassembly.

## AI-only schema

`document_chunk_sets` is a completion ledger: source content FK, source checksum, complete input fingerprint, chunking version/profile hash, current flag, terminal status, attempts/error diagnostics, chunk count, metadata and timestamps. A UNIQUE `(document_content_id, profile_hash, input_fingerprint)` prevents duplicate exact generations; a partial UNIQUE on current content permits one active generation. It also represents valid empty results without fake chunks. Errors roll back, record a failed generation with bounded attempts/error class, and remain pending without fabricated completed chunks. Failed counts refer to the current exact input; historical failed inputs remain separately visible.

`document_chunks` fields: `id`, `chunk_set_id`, `document_id`, `document_content_id`, `chunk_index`, `section_type`, nullable `section_number`/`section_title`, `language`, unchanged `content`, `content_hash`, nullable source URL, `source_checksum`, `chunking_version`, `quality_status`, JSONB metadata and timestamps. Hierarchy is source heading context in metadata; no parent chunk duplicates text. Metadata includes offsets, character/estimated token counts/method, subdivision boundaries/context, zero overlap, oversized/uncertain flags and original source format/quality/issues.

FKs restrict accidental source deletion; chunks cascade only when an AI generation is deleted. A composite `(content_id,document_id,language)` FK prevents linking a chunk to another translation. Unique `(chunk_set_id,chunk_index)` and indexes for document/language/order/quality support later consumers. CHECKs enforce nonempty chunks, hash formats, nonnegative indexes/counts and supported statuses. `ai_jobs` is an independent database queue; there is no embedding/vector table.

The existing content table has one authoritative current row per language, not an edition archive. The full fingerprint includes source document ID, source text, stored checksum, language, title, quality/issues, extraction metadata and source URL; even metadata-only changes are detected. The profile hash includes algorithm version, target size and overlap policy. Changing algorithm rules requires incrementing `config/ai.php` version. P2 has done this to legal-v2. Different profiles coexist. Historical v1 updated a source in place; P2 preserves changed-input editions as separate generations. An explicit `--force` atomically replaces only the selected exact generation; existing other-language/profile rows remain. Old profiles can become stale: future readers must match the desired profile AND current input fingerprint/checksum, not blindly consume all chunks.

## Commands

```bash
cd /home/xojiakbar/.codex/worktrees/ai-legal-chunking/mening_fikrim
php artisan legal:migrate
php artisan legal:chunk-documents --status
php artisan legal:chunk-documents --limit=20 --dry-run
php artisan legal:chunk-documents --document-id=1 --document-id=22
php artisan legal:chunk-documents --only-pending --limit=100
php artisan legal:chunk-documents --document-id=1 --chunk-size=2000 --force
php artisan legal:chunk-documents --only-pending --limit=20 --queue
php artisan legal:work --max-jobs=50
```

| Option | Behavior |
| --- | --- |
| `--limit=100` | At most 1..500 distinct selected documents; all stored languages of each selected document. Never unlimited by default. |
| `--content-limit=N` | At most 1..500 source representations, independently of the document limit. |
| `--content-id=ID` | Repeatable source content IDs, with both limits still enforced. |
| `--document-id=ID` | Repeatable positive source document IDs; still respects limit. |
| `--dry-run` | Computes results without set/chunk writes or dispatch. |
| `--force` | Regenerates only selected AI profile, within one transaction per representation. |
| `--only-pending` | Skips complete unchanged full fingerprints; changed text/checksum/quality/provenance remains eligible. |
| `--chunk-size=N` | Target Unicode characters; profile identity changes with size. |
| `--queue` | Bounded dispatch exclusively to `ai_jobs` / `legal-chunking`; skips unchanged representations. |
| `--status` | Read-only source/processed/set/chunk/queue counts and current profile hash. |

`--force`/`--only-pending` and `--dry-run`/`--queue` are mutually exclusive. Source rows stream by document in batches of ten; one source representation is parsed at a time. Completed fingerprints can be scanned to find pending work without loading the whole corpus into RAM. Per-source row locking serializes duplicate deliveries, including jobs; set/chunk replacement commits atomically. A command error reports document/content IDs and exception class, logs to the worktree's isolated log, continues other selected representations and exits nonzero. Existing committed sources remain safe; interrupted transactions roll back. Resume with the same bounded `--only-pending` command. `--force` can repair a damaged derived generation; source text is never rewritten.

## Implementation

- `AiDatabaseGuard`: fail-closed config/actual PostgreSQL database and schema checks.
- `AiDevelopmentServiceProvider`: isolated command/job/source HTTP restrictions; disabled in the existing ordinary parser environment.
- `DocumentStructureParser`, `LegalDocumentChunker`, `ChunkDocumentService`: structure, exact-text splitting, transactional persistence.
- `DocumentChunk`: explicit guarded AI connection and source-content relation.
- `ChunkLegalDocumentJob`: independent queue, bounded attempts/time, safe replay.
- `MigrateLegalDatabase`, `ChunkLegalDocumentsCommand`, `WorkLegalQueue`: explicit migration, bounded processing/status, isolated worker.
- `database/ai-migrations/2026_11_02_000001_create_legal_chunks.php`.
- `scripts/ai_snapshot.php`, `scripts/verify_legal_chunks.php`: separate provisioning and bounded acceptance evidence.

## Tests and real verification

Regression tests must use only `mening_fikrim_active_test`, with `.env.testing` copied from the secret-free example and local test credentials. Never run concurrent suites against that same test database. If optional PDF layout diagnostics are enabled, install the EXISTING pinned requirements in a separate venv and set `SCRAPING_PYTHON_BINARY` only in the worktree's ignored test env. No parser configuration is changed:

```bash
python3 -m venv /absolute/private/test-python
/absolute/private/test-python/bin/python -m pip install -r requirements-parser.txt
vendor/bin/phpunit
vendor/bin/pint --test
```

Historical P1 regression: **283 tests / 1,392 assertions PASS**; Pint PASS. Current P2 test results are in AI_CHUNKING_P2_REPORT.md. The new 27 tests cover Latin/Cyrillic/Russian, chapter/annex hierarchy, parts/points/subpoints, long articles, superscripts, no-structure fallback, OCR, PDF without OCR, short/empty/invalid UTF-8, oversized atomic text/tables, multilingual source identities, repeated command/job delivery, changed checksum/quality, dry-run/status/force/bounds, queue isolation/worker, rollback/retry, migration rejection and wrong configured/actual MAIN target refusal before writes. MAIN safety checks are mocks/config rejection; no real MAIN connection is opened by tests. Pint excludes historical `docs/` evidence to avoid formatting old PHP audit artifacts.

[verification.json](ai/verification.json) and [CHUNK_EXAMPLES.md](ai/CHUNK_EXAMPLES.md) show the bounded AI DEV acceptance: 15 documents, 19 representations, 2,295 chunks, zero errors, full byte coverage, unchanged repeated rows and all four restored source-table checksums unchanged. Mehnat kodeksi has 593 observed articles in each of Latin Uzbek, Cyrillic Uzbek and Russian. Both PDF text-layer and OCR review samples are included. This sample does not prove universal legal/OCR/complex-table correctness.

Historical `scripts/verify_legal_chunks.php` is frozen and now refuses execution to protect P1 evidence. Use the P2 scripts described above. It processes the fixed deterministic sample, not the entire corpus; no source requests. No persistent worker/scheduler is started.

## When GPU becomes available

Choose the embedding model/tokenizer/context size and multilingual retrieval policy; review quality/oversize cases; choose a stable chunk profile and increment its version when rules change. Consume only current fingerprints and desired quality/language/legal-status filters. Add explicitly authorized embedding storage/jobs keyed by chunk ID + chunk content hash + embedding model/version, then generate vectors and evaluate retrieval. GPU/LLM clients, Ollama/vLLM, RAG, pgvector and embedding generation remain unimplemented in this task.


## P3 — independent source and retrieval layers

The preceding v1/v2 algorithm and acceptance results remain historical. P3 retains every original source chunk row and its exact source partition, including oversized legal/table units. Current legal-v3 retrieval passages reference the current legal-v2 source generation; they split giant units and coalesce compatible short fragments without rewriting source text. Generation uniqueness/currentness is per content **and layer**, not one ledger row across both layers.

`legal:chunk-documents` now orchestrates both services through `ChunkingPipeline`; existing CLI document/content limits and the dedicated allowlisted queue remain. Successful matching generations are immutable; P3 force retries exhausted failures but does not delete/rebuild successful source or passage history. Search uses only current configured v3 passage results, retaining original chunk IDs for citations. `structured`/`needs_review` remain accepted aliases for retrieval `eligible`/`review`; source extraction status and human review remain separate.

See [UNIVERSAL_CHUNKING.md](UNIVERSAL_CHUNKING.md) for strategy/lineage/quality and version contracts, [AI_PIPELINE_COMMANDS.md](AI_PIPELINE_COMMANDS.md) for actual commands/recovery and [EMBEDDING_READINESS.md](EMBEDDING_READINESS.md) for the implemented builder and future-only integration/sync plan. Real P3 metrics and tests are in [AI_CHUNKING_P3_REPORT.md](AI_CHUNKING_P3_REPORT.md).
