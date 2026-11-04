# Embedding readiness — deterministic inputs, no model integration

## Implemented handoff

`EmbeddingTextBuilder::build(exactBody, passageMetadataWithLineage, policy)` returns deterministic `text`, SHA-256 `text_hash`, character length, approximate token count/method, body start character, context truncation flag, builder version, document/content/checksum/language/profile and original source spans. Reconstruct it from a persisted passage body plus its metadata and lineage; the prepared hash/length are stored without duplicating the full contextual text in the table.

The current policy is source `legal-v2`, retrieval `legal-v3`, builder `context-v1`, body <=3,500 characters, context <=512, total input <=4,096. Context uses only stored document title and actual section/chapter/article/point titles, deduplicates repeated title strings and skips a value already present in the body. It can truncate the context prefix, never source body text. Citation spans refer to the original source body, not the added prefix.

No matching model tokenizer was available in the local Hugging Face cache. No tokenizer/model was downloaded. `estimated_tokens=ceil(characters/4)` is explicitly `approximate_chars_div_4_no_model_tokenizer`; Uzbek/Cyrillic/OCR/table token counts can differ substantially. These character bounds do not establish a model-safe token bound. Future integration must use the exact selected tokenizer and validate context policy.

Six real-corpus handoff examples (three languages, giant appendix, OCR and short context) are in [embedding_examples.json](ai_p3/embedding_examples.json). Their reconstructed input hashes match the stored passage metadata; no model was called.

## Future integration contract (not implemented)

Planned identifiers supplied by the operator are `Qwen/Qwen3-Embedding-4B`, `Qwen/Qwen3-Reranker-4B` and main LLM `Qwen/Qwen3.8-27B-FP8`. They are planning labels only. Availability, immutable model revision, task prefix/template, tokenizer, context limit, dimension, normalization, pooling, quantization and serving behavior must be confirmed on the authorized GPU setup. No model specifications or endpoint health are assumed here.

An embedding item should be keyed by `(retrieval_generation_id, passage_id, prepared_text_hash, builder_profile, model_revision, embedding_configuration_hash)`. A model change invalidates only matching embedding identities, not source or passage bodies. A passage-policy change creates a new retrieval profile; only embeddings for the current retrieval generation may enter current search. A source/checksum/metadata change invalidates both current source/retrieval generations today and must also exclude their future embeddings immediately. Historical rows can remain auditable.

A future bounded embedding command will select only missing/failed matching identities, pin tokenizer/model/configuration in queue receipts, commit per source/bounded batch and checkpoint before dispatch. Separate embedding status/failure counters and retry budgets must distinguish permanent input errors from transport retries. Do not reuse parser queue/cache or make a large workflow engine. Interrupted jobs resume by identity; a completed embedding is reused. Embedding/reranker text must come from the same builder/hash; rerank windows must preserve passage-to-source spans.

Default retrieval suitability is `eligible`; `review` requires an explicit product/operator policy, and `blocked` must be excluded from embedding/search publication. Eligibility is not human verification. Citation responses must carry document/content/language/source URL, source checksum, retrieval/source generation IDs, passage ID, original chunk IDs, byte/character body span, structural context and quality reasons. Any future answer assembly must validate the current fingerprint before using cached results. There is no answer/RAG assembly today.

## Safe incremental MAIN → AI sync plan (future only)

No sync or new snapshot occurred in P3. The existing 10,036-document / 25,691-content fixed copy is the input. A future separately authorized sync should:

1. Read only the four source corpus tables under a bounded, consistent read-only MAIN view. Use a stable high-water mark plus checksum reconciliation for updates/deletions; timestamps alone are insufficient. Exclude queue/cache/campaign/failed jobs and all actor control.
2. Stage an immutable manifest outside MAIN with source IDs, language, source content/hash, metadata fingerprint and source snapshot watermark. Verify translations, identity conflicts and counts before applying anything to AI.
3. Apply bounded AI-only transactions through a dedicated source import path. Preserve existing source IDs/foreign keys and history, never overwrite verified text with an unreviewed candidate. Audit additions, updates, deletions/tombstones and changed metadata explicitly.
4. Let local invalidation clear current derived generations for changed inputs; queue only those new/changed/missing-profile inputs after reconciliation. A future embedding ledger similarly selects only missing current hashes. Deletions become audited exclusions, not silently reassigned identities.
5. Checkpoint manifest/batch receipts and resume without resetting budgets/history. Require exact preservation/attribution tests, source traffic isolation and separate operator authorization before enabling any automatic sync/schedule.

This is a technical plan only. Grammar/similarity/legal/assistant/analytics/transcription services, FAQ/transcript strategies, vector schema/index, hybrid retrieval and ASR/model inference remain future work.
