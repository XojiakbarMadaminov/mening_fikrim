# AI DEV P2 — full corpus chunking and keyword readiness

Completed on 2026-11-02T16:23:58+05:00. Worktree: `/home/xojiakbar/.codex/worktrees/ai-legal-chunking/mening_fikrim`; branch `feature/ai-legal-chunking`. Local changes remain uncommitted and unmerged. No deployment, new MAIN snapshot, synchronization, model/GPU/embedding/vector index or legal conclusions.

## Measured outcome

| Metric | Actual result |
| --- | ---: |
| Source documents | 10,036 |
| Source content representations | 25,691 |
| Current processed representations | 25,691 |
| Pending representations | 0 |
| Failed current inputs | 0 |
| Skipped authoritative contents | 0 |
| Current chunks | 549,454 |
| Retained historical v1 chunks | 2,295 |
| All stored chunks | 551,749 |
| Structured current chunks | 240,866 |
| Needs-review current chunks | 308,588 |
| Unstructured/fallback contents | 4,654 |
| Contents with review chunks | 9,991 |
| Over target chunks (4,000 chars) | 10,392 |
| Over maximum review threshold (8,000 chars) | 4,844 |
| Short chunks (<80 chars) | 68,614 |
| Missing source URLs | 0 |
| Current chunks containing tables | 44,080 |
| Duplicate chunk identity groups | 0 |
| Multiple-current-source groups | 0 |

Structured: **43.84%**; needs_review: **56.16%**. Lengths: minimum 6, maximum 748,614, average 889.08 Unicode characters. Version `legal-v2`, profile `da8834b38e162624ff53c2862f076f805b84a03c9286221745b22cf6aa0deac5`. These are current-generation metrics; retained v1 rows never enter current retrieval.

## P2.1–P2.9 evidence

| Stage | Result |
| --- | --- |
| Audit | Actual start: 10,036 documents / 25,691 representations / 19 v1 sets / 2,295 chunks / zero AI receipts. Existing parser actors were live. |
| Quality review | 25 real source comparisons v1/v2; exact text preserved; no review promotion; v1 chunk rows unchanged. |
| pilot100 | completed: 100 additional representations in 1 bounded batches; 2026-11-02T15:26:06+05:00 to 2026-11-02T15:26:16+05:00. |
| pilot1000 | completed: 1,000 additional representations in 10 bounded batches; 2026-11-02T15:27:24+05:00 to 2026-11-02T15:28:36+05:00. |
| full | completed: 24,591 additional representations in 246 bounded batches; 2026-11-02T15:28:48+05:00 to 2026-11-02T16:03:07+05:00. |
| Full validation | Every current source passed byte offsets/order/SHA/source identity/full reconstruction; copied source table SHA checks match the original snapshot. |
| Keyword retrieval | Six real script/filter/attribution checks pass; current profile only, bounded pagination/query time, literal nullable source URLs. |
| Regression | 295 tests / 1468 assertions PASS; Pint and diff checks PASS. Tests only mening_fikrim_active_test, no real Lex requests. |
| Documentation | Updated AI runbooks/P1 continuation and generated quality/boundary/query/resource artifacts. |

The original `--limit` counts distinct documents and includes their stored languages. P2 added `--content-limit` and repeatable `--content-id`; coordinator batches contain at most 100 EXACT source representations. Pilot1000 is an ADDITIONAL 1,000 after pilot100, so the two gates cover 1,100 inputs. No unlimited queue dispatch or whole-corpus transaction was used.

## Isolation and resources

All corpus/schema/queue writes use `pgsql_ai` / `mening_fikrim_ai_dev`; tests use only the separate test database. The source copy's four complete-row hashes are unchanged: PASS. MAIN received only bounded read-only observation; no migrations/grants/data writes, queue/cache/campaign actions, parser stop/restart or source requests. Original parser Git status identical: yes. Actor PID set identical: yes. MAIN at first observation: 6512 NEW / 52023 HTTP / running; final: 6659 NEW / 53162 HTTP / running. No zero-impact throughput guarantee is inferred from liveness.

One AI worker at a time, separate advisory mutexes/queue/table, one logical CPU affinity, nice10/low I/O priority, 10ms between deliveries and 250ms between batches. Worker maximum measured RSS: 177.88 MiB; total measured worker user+system CPU across completed batches: 459.91 seconds. PostgreSQL backend CPU/shared cache are not separately attributed to those worker figures. Coordinator pauses before new work below 3 GiB available RAM or 10 GiB disk. PHP limit 384 MiB; worker checks 256 MiB between jobs; bounded per-source transactions and 100-row inserts. Full phase receipts/checkpoints and per-batch time/CPU/RSS are in phases.json and resource artifacts.

## Generation and quality decisions

`legal-v2` recognizes named document parts, clears obsolete section/chapter context at title/annex boundaries, and marks unrecognized structure needs_review. No source text is repaired/rewritten or source needs_review promoted. Target/max/structure/zero-overlap/version form a stable profile. Long indivisible sentences/tables remain exact and flagged; they are not asserted ready for a model context window. Tokens remain a labelled chars/4 heuristic. Short heading chunks also require retrieval evaluation before embedding policy is finalized.

AI migration 000002 adds generation input-history uniqueness, current flag/partial uniqueness, retry diagnostics and source/URL invalidation triggers. A changed source gets a new generation; old chunks remain. Only one successful matching generation is current, activated atomically after persistence. Queued jobs pin profile/version, failed current inputs have at most three attempts, transaction/duplicate delivery recovery is tested. Old v1 chunk-row hash preserved: yes. Pending candidates are preserved as source metadata but are not authoritative source representations for this stage.

Full validation reconstructed 25,691 current representations / 549,454 chunks / 651,169,442 bytes, with 0 errors. A repeated pending command returned `{"selected_documents":0,"selected_contents":0,"errors":0}` with unchanged chunk count/IDs/hashes. Repeated identical hash groups within a source: 2,852; these are legitimate repeated source text at distinct offsets, not duplicate generation identities.

## Language and category distribution

| Language | Representations | Current chunks |
| --- | ---: | ---: |
| en | 108 | 9,479 |
| ru | 5,767 | 116,871 |
| uz-Cyrl | 9,969 | 215,855 |
| uz-Latn | 9,847 | 207,249 |

| Stored document category | Documents | Current chunks |
| --- | ---: | ---: |
| Qaror (Resheniye) | 1 | 3 |
| Декларация | 1 | 20 |
| Договор | 1 | 21 |
| Закон | 281 | 31,850 |
| Закон Республики Каракалпакстан | 25 | 1,282 |
| Изменения и дополнения | 1 | 24 |
| Кодекс | 9 | 13,166 |
| Коммюнике | 1 | 4 |
| Меморандум | 6 | 198 |
| Нормативы | 5 | 295 |
| Определение | 1 | 3 |
| Перечень | 5 | 10 |
| Положение | 1 | 248 |
| Постановление | 6,932 | 395,574 |
| Правила | 9 | 2,315 |
| Приговор | 1 | 8 |
| Приказ | 558 | 48,235 |
| Программа | 3 | 52 |
| Протокол | 2 | 37 |
| Распоряжение | 44 | 1,581 |
| Решение | 1,714 | 15,831 |
| Решение (Конституционного суда) | 4 | 108 |
| Соглашение | 22 | 832 |
| Стандарт | 6 | 108 |
| Требования | 1 | 132 |
| Указ | 402 | 37,517 |

Source legal status counts, section types, source-quality issues, chunk review reasons, oversized examples and min/max/mean lengths are in final_validation.json; no category normalization or language translation was fabricated.

## Keyword retrieval

`LegalKeywordSearch` and `legal:search` provide PostgreSQL simple-lexeme matching, bound parameters, language/category/quality filters, stable ID pagination, full source attribution and a five-second read-only query budget. Default quality is structured; review/all are explicit. No Uzbek/Russian stemming, transliteration, semantic ranking or legal/date applicability decision is claimed. Missing URLs stay null.

AI migration 000003 created an initial concurrent structured-only GIN expression index. Full-corpus review/all queries reached the five-second bound; migration 000004 replaced it with an all-quality lexical GIN index after oversized-text preflight. Candidate IDs are selected first and source/JSON metadata loaded second in the same repeatable-read/read-only snapshot, avoiding a measured wide-row LIMIT plan failure; no embedding/vector extension or external engine. An exploratory EXPLAIN exceeded 44 seconds and only that AI query was canceled; this is not used as an ordinary-search latency baseline. Controlled same-snapshot bitmap-on/off plans are recorded separately, with unrelated primary-key index scans retained. Final measured indexed query: 42.509 ms; GIN-disabled comparison: timed out within its five-second budget. Where both finish, returned IDs are compared exactly. Needs-review/all can be slower and may require narrower terms/filters.

```bash
php artisan legal:search mehnat --language=uz-Latn --quality=structured --limit=10
php artisan legal:search меҳнат --language=uz-Cyrl --quality=all
php artisan legal:search труд --language=ru --category=Кодекс
```

## Operator review and unresolved issues

22 real samples across 13 groups cover Latin/Cyrillic Uzbek, Russian, laws, decisions, decrees, regulations, codes, PDF, OCR, review, tables and very large sources. REVIEW_PACKET.md/JSON compare original source windows and exact chunk offsets/excerpts. This is an automated/agent inspection packet: **no human operator review occurred and no manual_verified status was set**. OCR, layout/table semantics, unknown structure, tiny heading chunks, giant atomic chunks and exact tokenizer fit remain review/evaluation work. Source verified means automated source quality, not legal/human certification.

## Continue and prepare embeddings

The completed full coordinator phase is a no-op. If new explicitly authorized AI inputs later exist, use bounded pending processing; the current fixed snapshot has no pending work:

```bash
php artisan legal:chunk-documents --status
php artisan legal:chunk-documents --only-pending --content-limit=100 --limit=500 --queue
php artisan legal:work --max-jobs=300 --rest-ms=10
# Resume a previously interrupted P2 full phase (never MAIN/parser):
taskset -c 19 nice -n 10 php -d memory_limit=384M scripts/ai_p2_collect.php --phase=full
```

The current chunk profile is ready as a deterministic attributed dataset, with quality exclusions. GPU next steps: choose/test Qwen Embedding model and exact tokenizer/context policy; review/resolve oversized and needs_review data; authorize model-versioned embedding storage/jobs keyed by current chunk ID+hash; implement GPU inference; evaluate multilingual retrieval/legal-status/date filtering before any legal conclusions. Nothing in that list was executed here.

Future MAIN-to-AI sync is a separately authorized task: a read-only consistent incremental export with an updated_at+ID watermark, checksum/metadata overlap checks and transactional document/source/identifier/content bundles; apply only into AI through a validated staging/import path, preserve canonical identity and historical generations, invalidate changed inputs and regenerate bounded current chunks. Verify source-side deletions/legal changes explicitly instead of silently removing text. If timestamps/identity are unreliable, reconcile checksums in bounded windows. Never copy operational queues/campaigns, change MAIN schema, assume timestamps alone capture all changes or automatically expand scope. No sync/new snapshot was performed in P2.

## Files and evidence

Implementation: current-generation extension of ChunkDocumentService/DocumentStructureParser/LegalDocumentChunker/ChunkLegalDocumentsCommand/ChunkLegalDocumentJob/WorkLegalQueue; new LegalChunkVerifier,LegalKeywordSearch,SearchLegalChunks; AI migrations 000002/000003/000004; P2 acceptance/report scripts and LegalCorpusP2Test/unit extensions. Tests include 1,000 synthetic source representations in the dedicated test DB, interrupted transaction/duplicate delivery, edition/current selection, checksum/quality/URL invalidation, retry cap/recovery, very large text, exact repair, keyword/script/quality/category/pagination/attribution and MAIN refusal; original parser regressions remain passing.

Evidence: [before.json](ai_p2/before.json),[version_comparison.json](ai_p2/version_comparison.json),[phases.json](ai_p2/phases.json),[final_validation.json](ai_p2/final_validation.json),[content_validation.jsonl](ai_p2/content_validation.jsonl),[REVIEW_PACKET.md](ai_p2/REVIEW_PACKET.md),[search_acceptance.json](ai_p2/search_acceptance.json),[search_index_comparison.json](ai_p2/search_index_comparison.json),[test_results.json](ai_p2/test_results.json),[parser_start.json](ai_p2/parser_start.json),[parser_end.json](ai_p2/parser_end.json). P1 artifacts remain in docs/ai unchanged.


## Historical continuation note — P3

All P2 results and `docs/ai_p2/` evidence above remain historical and unchanged. The subsequent isolated P3 task retains the exact legal-v2 source chunks and adds independent bounded legal-v3 retrieval passages, quality axes and offline evaluation. Current P3 results are recorded separately in [AI_CHUNKING_P3_REPORT.md](AI_CHUNKING_P3_REPORT.md) and `docs/ai_p3/`.
