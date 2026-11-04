# AI DEV P3 — universal chunking, quality and GPU readiness

## Execution status

**Completed and validated on 2026-11-03.** All 25,691 representations have current v3 retrieval generations, with 509,018 exact, bounded passages. Independent full validation, repeated-run row hashes, keyword acceptance, 120-query evaluation, 316 tests / 9,330 assertions and Pint passed. All work is confined to the existing `feature/ai-legal-chunking` checkout and `mening_fikrim_ai_dev` copy. The snapshot has not been refreshed or synchronized. No MAIN write, parser control/source call, GPU/model/embedding/vector/RAG integration, merge or deployment is included.

## Audit of real P2 data

The starting database agreed with the P2 report: 10,036 documents, 25,691 content representations, 549,454 current legal-v2 chunks, 551,749 total source chunks including 2,295 v1 rows, no pending/failed source inputs. P1/P2 source evidence remains historical.

Of 549,454 current source chunks, 308,588 (56.16%) were needs_review. The reason counts overlap: 296,478 source_needs_review, 30,457 structure_unrecognized, 16,015 structure_uncertain, 10,392 oversize and 4,844 above maximum. Source extraction review explains most review rows: a relatively smaller set of 6,192 source representations needing review generates many large chunks. This cannot be fixed by changing a chunk label. OCR/source gaps, text damage and complex table layouts remain review work; no source extraction status is promoted.

Oversized chunks came primarily from atomic tables/appendices and long paragraphs/points retained by P2's exact-source partition. The largest is original chunk 188626, document 3637 / content 8852, a 748,614-character appendix from the ko‘kalamzorlashtirish decision, [recorded source URL](https://lex.uz/uz/docs/-5801444). Large parallel Cyrillic/Latin appendices and investment/preschool tables occur among other top examples. These are preserved source units, not permission to discard table cells or rewrite law text. Bounded derived passages solve input size without altering them.

The 68,614 source chunks below 80 characters included 21,979 paragraphs, 16,646 points, 14,677 chapters, 7,371 subpoints, 5,040 headings, 2,328 appendices and 249 titles. Many are meaningful headings/numbered units. They are retained and can contribute to a contextual/coalesced passage. Exact source reassembly is the preservation criterion, not deleting inconvenient short rows.

[before.json](ai_p3/before.json) preserves the real audit, existing index definitions, review/length/format distributions and all historical source chunk row hash. It is independent of P2 evidence.

## Implemented design and reuse

The minimal pure `ChunkingService` chooses a `ChunkingStrategy`. `LegalChunkingStrategy` consumes the existing exact v2 segments; minimal `TextChunkingStrategy` provides an unstructured fallback contract for already extracted text. `BoundedTextSplitter`, `ChunkQualityAnalyzer` and `EmbeddingTextBuilder` separate exact splitting, quality classification and contextual input preparation. There is no FAQ/transcript framework or new extraction technology.

P2's structure parser, source chunker/fingerprint, current-generation ledger/invalidation triggers, guard, independent database queue, allowlisted worker and CLI are reused. `ChunkingPipeline` orchestrates the source/retrieval services; commands/jobs do not copy business logic. P3 adds no parser command or ordinary migration.

Source and retrieval generations are independent: source **legal-v2** remains the authoritative exact partition; derived **legal-v3** is the current retrieval profile. This avoids duplicating/rebuilding over half a million source rows merely to change model input. Search ranks v3 passage IDs; returned v2 source IDs are citation lineage only. One current generation per `(content_id,layer)` is enforced by SQL. Old generations remain auditable. Failed new retrieval generations roll back atomically and preserve prior valid current history. Matching identities are reused; changed source fingerprints/configuration create new generations. P3 force never deletes successful source/passage rows.

Migration **000005** adds the ledger layer/parent relationship and `retrieval_passages` with exact byte/character offsets, hashes, original chunk lineage, quality/context metadata, unique generation/index identity and translation-safe foreign keys. Migration **000006** adds a single concurrent passage-body lexical GIN. Actual 28,147-passage probes before that index took 4,863/4,899/4,871 ms and EXPLAIN showed sequential scans. The existing all-quality GIN indexes another physical relation (`document_chunks`) and cannot serve passage bodies; it remains intact. Index maintenance is bounded to zero parallel maintenance workers/32 MiB. No vector index exists.

The v3 profile is `c55997d09734ae122389f6a06cee4367ff9438076375e1498dba14a4893c3548`. It pins source profile, retrieval version, body/context/input/merge policy and builder version. The current target/body/context/input character bounds are 3,000 / 3,500 / 512 / 4,096. Paragraph/line/table row/sentence/clause/word/UTF-8 fallback cuts retain semantic uncertainty flags. Short coalescing respects observed structural scope and original fragment lineage. Bodies concatenate to the exact source. Character offsets count Unicode codepoints, separately from byte offsets; forced cuts do not certify grapheme or legal semantic integrity.

Source extraction status/issues, structural recognition, retrieval suitability and human review are distinct axes. Unrecognized structure alone does not disqualify intact verified source text. Source review, damage indicators, tables and uncertain semantic boundaries retain review suitability. Every automated human-review field is `not_reviewed`; eligibility is technical suitability, not human/legal verification. The old source needs_review count is unchanged, rather than cosmetically reduced. Reason totals overlap.

Implementation contracts: [UNIVERSAL_CHUNKING.md](UNIVERSAL_CHUNKING.md), [AI_PIPELINE_COMMANDS.md](AI_PIPELINE_COMMANDS.md), [EMBEDDING_READINESS.md](EMBEDDING_READINESS.md).

## Pilots and quality gate

The exact first pilot was a recorded diverse 100-source sample, not the first 100 IDs. It included 40 uz-Latn / 38 uz-Cyrl / 19 ru / 3 en representations, nine document types, 92 HTML / 4 PDF / 4 OCR and 57 verified / 43 needs_review source representations. It explicitly included the largest table units, short headings/points and extraction-review cases. [pilot_sample.json](ai_p3/pilot_sample.json) records IDs/reasons.

| Measure | Diverse 100 | Additional 1,000 | Combined 1,100 |
| --- | ---: | ---: | ---: |
| Sources independently verified | 100 | 1,000 | 1,100 |
| Retrieval passages | 9,924 | 18,223 | 28,147 |
| Maximum body characters | 3,496 | 3,500 | 3,500 |
| Maximum prepared input characters | 3,844 | 4,012 | 4,012 |
| Bodies below 80 characters | 84 | 832 | 916 |
| Prepared inputs below 80 characters | 1 | 4 | 5 |
| Coverage/lineage verification errors | 0 | 0 | 0 |

The same 1,100 sources previously had 27,125 exact v2 chunks, 3,127 short source bodies and 344 source chunks above 4,000, maximum 748,614. Their source chunks remain unchanged. The separate v3 layer reduces short/contextless model inputs without falsely redefining source length. In the giant appendix example, 251 passages cover its exact 749,020 bytes, maximum body 3,022 and review suitability retained: [giant_example.json](ai_p3/giant_example.json).

The combined pilot's suitability was 9,799 eligible / 18,348 review. Source-extraction review accounted for 18,288, table layout 8,323, potential damage 5,064, unrecognized structure 4,604, short bodies 916, OCR uncertified 10 and uncertain semantic boundaries 2 (overlapping reasons). The 278 passages from verified but structurally unrecognized source were not automatically treated as unusable. Human review remained absent. These new axes are not directly interchangeable with P2's one-bit structured/review label.

[quality_gate.json](ai_p3/quality_gate.json) passed: exact source reconstruction/attribution, zero duplicate current per layer, no unexpected failures/queue receipts, body/input bounds and full source preservation. All four original source-table row hashes match the initial P1 manifest. Every one of 551,749 historical source chunk rows matches the pre-P3 SHA-256 `c1330f1ec94226f1904685be678453ac677436bfc386493131bf1185e0a48e66`.

Measured pilot relation growth was 79,863,808 bytes. Extrapolation by actual processed source bytes with a 2x margin projected 2,422,221,488 bytes (~2.26 GiB) of full growth, compared with 113,067,765,760 bytes free at the gate. Maximum pilot worker RSS was 176,276 KiB (~172.14 MiB). This justified continuing the existing snapshot with the same bounds.

## Resource controls and tests

One AI worker only; CPU 19 affinity; coordinator nice 10 and inherited/additional worker nice 19; I/O class 2 priority 7; at most 100 source IDs per dispatch / 300 deliveries; source-level transactions; 10 ms job rest / 250 ms batch pause; 384 MiB PHP limit / 256 MiB between-job worker check; minimum 3 GiB available RAM / 10 GiB free disk. Independent AI advisory locks prevent another coordinator/worker. Inflight IDs are journaled before dispatch and resumed before new work. Unexpected receipts/profile drift cause a safe stop. Scheduling is disabled.

Full regression: **316 tests, 9,330 assertions, passed**, 03:35.384; **Pint passed**. All automated database tests use `mening_fikrim_active_test`; default HTTP stray requests are blocked. Twenty-one P3 tests cover deterministic UTF-8/multiple languages, long sentences/paragraphs/tables, legal structure/short headings, OCR/source-quality separation, context bounds, exact lineage, immutable v2, current-only search/filters/bindings, source-change invalidation, dry-run, transactional failure, idempotence, retry limits, queue profile pinning MAIN guard rejection and a bounded 1,002-article pagination fixture. Existing 295 P2/parser tests remain passing. [checks.json](ai_p3/checks.json) records the results.


## Full corpus and independent final validation

The full phase completed **2026-11-03 18:05:53 Asia/Tashkent**, after 48m22s for the full stage (17:17:31 start). Pilot/full journals contain exactly 25,691 distinct content IDs, with no duplicate selection or skipped authoritative input: 100 + 1,000 + 24,591. All batch checks passed. Independent final verification ran 18:06:07–18:13:18 and passed every source/passage, including exact reconstruction of **651,169,442 source bytes**.

| Final measure | Actual result |
| --- | ---: |
| Fixed source documents / representations | 10,036 / 25,691 |
| Current exact source chunks (legal-v2) | 549,454 |
| All preserved source chunks (v1 + v2) | 551,749 |
| Current retrieval passages (legal-v3) | 509,018 |
| Completed current source / retrieval generations | 25,691 / 25,691 |
| Pending / failed source or retrieval inputs / queued receipts | 0 / 0 / 0 |
| Duplicate current per layer / generation identity / passage identity | 0 / 0 / 0 |
| Maximum original source chunk / passage body / prepared input (characters) | 748,614 / 3,500 / 4,012 |
| Oversized retrieval bodies / prepared inputs | 0 / 0 |
| Source bodies below 80 characters (unchanged v2) | 68,614 |
| Retrieval bodies / prepared inputs below 80 characters | 23,373 / 68 |
| Eligible / review / blocked retrieval passages | 205,810 / 303,208 / 0 |
| Missing source URLs | 0 |
| Human-reviewed passages | 0 |

The four source-table complete-row hashes and all 551,749 old source chunk row hashes remain identical. Both layers have exactly one current generation for every representation; two current ledger rows per representation are expected, one per layer. v1/v2 history is retained. A real `--only-pending --content-limit=100` repeat selected zero documents/contents, returned exit 0 and preserved every complete passage row, SHA-256 **79f603775914d09b34bbeeba3ef850a48518fc6a9b81aad45d843e406f2f7661**.

Full review reasons (overlapping) are 300,817 source_extraction_review; 106,589 table_layout_review; 86,012 potential_text_damage; 58,644 structure_unrecognized; 23,373 short_body; 506 ocr_uncertified; 90 semantic_boundary_uncertain. Source quality itself is unchanged. The new review ratio is approximately 59.57%, versus the historical v2 56.16% on a different partition/definition; this is **not** presented as a cosmetic reduction. Splitting large reviewed sources and explicitly flagging tables changes the denominator. There are 11,120 passages from verified but structurally unrecognized source, assessed separately for technical suitability. No automated output becomes human verified.

Languages are uz-Latn 9,847 representations / 195,576 passages; uz-Cyrl 9,969 / 198,002; ru 5,767 / 107,993; en 108 / 7,447. Categories and independent quality axes are in [final_validation.json](ai_p3/final_validation.json); [content_validation.jsonl](ai_p3/content_validation.jsonl) records every source's check.

Actual total relation growth was **1,578,328,064 bytes (~1.47 GiB)**, below the pilot's margin estimate. The passage relation uses 1,323,581,440 table bytes + 212,197,376 index bytes; its lexical GIN is 167,862,272 bytes (~160.09 MiB), valid. The source lexical GIN remains valid and unchanged in purpose. Disk remained above 100 GiB free. Across all pilot/full worker deliveries, elapsed worker time sums to 1,887.02s, CPU user/system to 458.49/90.80s and peak RSS to 176,276 KiB (~172.14 MiB). These are AI worker measurements, not a claim of zero shared-host overhead or a controlled parser throughput benchmark. Resource observations show healthy RAM/disk and unchanged parser process start identities.

Six real, reconstructable embedding-input examples are in [embedding_examples.json](ai_p3/embedding_examples.json). Stored/reconstructed hashes agree. Bodies/context are deterministically prepared, tokens remain approximate, and model calls are zero.


## Keyword acceptance and offline retrieval evaluation

Current v3 passage search passed actual Latin/Cyrillic/Russian queries, category and eligible/review/all filters, source hash/span/current-profile checks, separate pages without duplicate IDs and bound SQL-shaped input. The final acceptance's index probes were 43.67 ms (`fuqarolar`), 37.51 ms (`ҳуқуқ`) and 43.93 ms (`порядок`) on 509,018 passages; EXPLAIN uses the passage GIN. The earlier 28,147-row no-index scans took roughly 4.9s. These are real local probes, not a controlled hardware benchmark. [search_acceptance.json](ai_p3/search_acceptance.json) contains results/citations and the plan.

The initial acceptance artifact is retained as [search_acceptance_initial.json](ai_p3/search_acceptance_initial.json). Its zero-match assumption for SQL-shaped text was incorrect: legal table/math bodies can contain the literal tokens x/OR/1. The corrected check verifies every returned body against the bound `plainto_tsquery` and the result bound; it passed. This diagnostic correction changed no corpus or search query semantics.

[retrieval_queries.json](ai_p3/retrieval_queries.json) contains **120 distinct language/query pairs**: 40 per uz-Latn, uz-Cyrl and ru; 30 document titles, 30 article headings, 30 legal phrases, 9 short questions, 9 broad questions, 6 paraphrase candidates and 6 multi-document candidates. Candidate units are sampled per document before the pool limit, preventing one large code from dominating selection. Every scored item records its real source document/content/chunk, actual section/title, literal byte span/checksum, expected current passage IDs and machine verification status.

**90 exact-source-anchor known items** have independently rechecked literal source spans and simple lexemes. Their labels are machine-verified silver known-item labels, not human gold, exhaustive legal relevance or proof of legal correctness. **30 uncertain semantic/question/multi-document cases stay unverified and are excluded from relevance metrics.** No human verification or model generation is claimed. Future human qrels are still needed for semantic/legal evaluation.

| Same offline dataset, quality=all, top 10 | v2 source keyword baseline | v3 passage keyword baseline |
| --- | ---: | ---: |
| Scored / unverified excluded | 90 / 30 | 90 / 30 |
| Recall@5 | 40.00% | 40.00% |
| Recall@10 | 47.78% | 48.89% |
| MRR@10 | 0.32205 | 0.33507 |
| Exact source-span hit@10 | 47.78% | 48.89% |
| Top-10 latency p50 / p95 | 14.89 / 72.45 ms | 12.27 / 75.80 ms |
| Maximum latency | 274.56 ms | 410.49 ms |
| Errors/timeouts | 0 | 0 |

[retrieval_evaluation.json](ai_p3/retrieval_evaluation.json) records every returned ID, expected-document/span rank, scoring eligibility and measured latency. PostgreSQL simple lexemes provide no morphology, synonymy or reranking. Deterministic ID ordering is ingestion-dependent, so the small v2/v3 metric difference is not claimed as a causal semantic-quality improvement. Recall is limited even on this known-item set; lexical match presence alone does not prioritize the expected document. These are honest baselines for later ranked/hybrid/model comparison.

## MAIN/parser isolation at completion

[parser_start.json](ai_p3/parser_start.json) (16:50:40) and [parser_end.json](ai_p3/parser_end.json) (18:17:53) are fixed, READ ONLY observations. The same operator-started actors **794970 / 794971** retained start ticks **10512698**; no stop/restart/recovery/control command was invoked. Original checkout Git status is identical, including its existing staged files. MAIN migrations stayed **13**, failed_jobs stayed **43**. No MAIN migration, source write, queue/cache/campaign modification or new campaign was performed.

The independently running parser naturally advanced from **11,791 to 12,141 NEW** and **85,187 to 87,275 HTTP**; MAIN source documents/contents grew from 15,480/37,847 to 15,826/38,626. Those are observations of the existing parser, not AI source traffic or AI synchronization. AI source counts remain the old fixed 10,036/25,691 snapshot and its exact row hashes. Source HTTP/model/GPU calls by this task are **0**. MAIN was read only for audit; its naturally changing rows are not falsely described as globally frozen. AI collection/validation/evaluation/test workers have exited and `ai_jobs` is empty. No Git commit/merge/deploy or new snapshot/restore was made.

## Commands, incremental behavior and remaining work

The implemented order is `legal:migrate` → `legal:chunk-documents --status/--dry-run/--only-pending --content-limit=... --queue` → `legal:work --max-jobs=...` → `legal:search`. Direct `--content-id` and original document limits remain supported. `--limit` counts documents; `--content-limit` counts representations. Queue jobs pin the profile; the coordinator records inflight IDs and resumes bounded work, while completed phases are no-ops. See [AI_PIPELINE_COMMANDS.md](AI_PIPELINE_COMMANDS.md) for the exact commands and stage gates.

New/changed input is selected by missing current profile/checksum and invalidation triggers, then matched by the full source fingerprint under locks. Existing successful outputs are reused; changed algorithm/configuration creates a separate generation without deleting source/history. A future model change belongs to a distinct embedding identity, so it must not rebuild source chunks. No automatic source arrival/sync/scheduler is enabled today.

Unresolved source extraction/OCR damage, table-layout semantics, forced semantic/codepoint cuts and **68** still-short prepared inputs remain visible. Keyword ordering/recall and unverified semantic/multi-document relevance need further work; other document types have only the minimal strategy contract. Source oversized units intentionally remain intact. No human review was performed and no reviewed label was fabricated.

GPU integration still needs explicit authorization, a deployed/validated model endpoint and immutable model revision, exact tokenizer, real context/dimension/pooling/normalization/template specifications, embedding identity/storage/jobs and separately scoped vector/hybrid/reranker/RAG work. Planned Qwen identifiers are unverified planning labels, not connected services. [EMBEDDING_READINESS.md](EMBEDDING_READINESS.md) specifies the current prepared-text/hash/source contract, future invalidation/citation/reindex-resume design and a **future-only** safe MAIN→AI incremental sync plan. Parser/chunking/embedding remain separate responsibilities.
