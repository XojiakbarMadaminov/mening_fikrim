# Universal chunking — implemented P3 contract

The engine runs offline in the isolated AI checkout/database. Source extraction and chunking remain separate. `ChunkingService::build(type, exactText, sourceSegments, metadata, policy)` is pure and deterministic. It accepts `legal` and minimal `text` strategies, returns exact passage bodies and lineage, and never calls a model, HTTP client or database. `ChunkingStrategy::segments()` is the small extension point. A new source type supplies observed segments/context; FAQ pairs or transcript timestamps require an actual future strategy. They are not implemented or inferred today. Text extracted from PDF/DOCX can enter the text contract; extraction itself is outside this engine.

## Reuse and two independent layers

P2's `DocumentStructureParser`, `LegalDocumentChunker`, source fingerprint, guards, ledger, database queue, commands and source invalidation triggers are reused. The authoritative exact source layer stays **legal-v2**. Its existing 549,454 current chunks, including legitimate large atomic legal units, are not rewritten. All v1/v2 historical chunk rows remain byte-for-byte unchanged.

The **legal-v3 retrieval layer** references the matching source generation and original chunk IDs. `document_chunk_sets.layer` distinguishes source/retrieval; the partial unique index permits one current generation per `(document_content_id, layer)`. The existing generation identity remains `(content_id, profile_hash, input_fingerprint)`; the retrieval profile hashes the layer, source profile, retrieval version, target/max/context/input bounds, merge threshold, overlap and input-builder version. Any output-affecting code change requires a version/profile change.

`PassageGenerationService` locks the source document/content, verifies its matching current source generation and writes passages plus ledger in one source transaction. Only a complete generation becomes current. An exception rolls back its passages, records bounded retry diagnostics separately and retains a previous valid current retrieval generation. A source/metadata change invalidates both layers through existing triggers; obsolete results remain history but are excluded from current search. A successful matching generation is immutable/reused, including under P3 `--force`. Force permits retry of exhausted failures; changing a successful result requires a new version/profile. It never force-rebuilds v2 source rows in the enabled P3 pipeline.

No seventh parser business table, workflow engine, repositories or parser changes were introduced. AI-only migration 000005 adds the retrieval layer/table, offsets/quality/lineage and foreign keys. Migration 000006 adds one measured, necessary lexical GIN on that new physical relation. The old source GIN cannot index another relation and remains for v2 history/comparison. Index creation is concurrent outside tests with zero parallel maintenance workers and a 32 MiB maintenance budget.

## Exact bodies, bounded inputs

`LegalChunkingStrategy` consumes actual v2 segments, not a re-parsed copy. `TextChunkingStrategy` offers an unstructured segment with an explicit structural fallback flag. `BoundedTextSplitter` prefers paragraph, line/table row, sentence, clause and word boundaries, then UTF-8 codepoint cuts. The current policy targets 3,000 characters, caps bodies at 3,500, caps context at 512 and caps the complete prepared input at 4,096. These are local character limits, **not claims about model token limits**.

Large source units are retained intact in the source layer and represented by several passages. Table content is never regenerated or normalized. Row/line boundaries are preferred, but tables always retain layout-review flags. Uncertain word/codepoint cuts and their continuation carry semantic-boundary flags. UTF-8 codepoints are preserved; this does not promise grapheme/semantic integrity at forced cuts.

Short adjacent fragments can coalesce within the same observed structural scope and bound. Standalone, exactly observed parent headings can join their compatible descendant. A source boundary is not silently discarded: all contributing IDs and fragment offsets remain in lineage. Different articles/chapters/documents cannot be mixed by the coalescing policy. Repeated parent context helps short inputs without deleting source fragments. Some short inputs remain legitimately short.

Every passage stores contiguous start/end **byte** and Unicode **character** offsets, content SHA-256, source IDs/language/checksum, URL (nullable if unknown), actual document/section/chapter/article context, source fragment IDs/bounds, profile and independent quality axes. `single_unit` is null when a passage combines several units; lineage lists each unit separately. Context contains observed metadata only, never inferred law names or numbers.

Bodies are non-overlapping and concatenate to the exact stored source bytes. `PassageVerifier` independently verifies all indices, byte/character boundaries, body hashes, parent identity/fingerprint/checksum, each original chunk's referenced boundaries, prepared-input hashes/limits and unchanged source/human quality. It verifies original data, not just stored `coverage_verified` flags.

## Four quality axes

- **Source extraction:** original status, format and quality issues are copied, never upgraded. OCR is uncertified.
- **Structure:** observed or unrecognized. Unrecognized structure alone does not disqualify complete verified source text.
- **Retrieval suitability:** eligible / review / blocked. Source review, text-damage indicators, table layouts and uncertain semantic cuts require review. Blank bodies are blocked. Eligible means technical suitability, not legal correctness or human verification.
- **Human review:** `not_reviewed` for every automatically prepared passage.

Reasons include source_extraction_review, ocr_uncertified, table_layout_review, semantic_boundary_uncertain, structure_unrecognized, short_body, potential_text_damage and no_lexical_text. Counts overlap. Existing source quality issues are also retained verbatim. Text-damage heuristics are diagnostics, not OCR repair. Uncertain headings remain inherited observed context, not certified legal structure.

## Current keyword interface

`LegalKeywordSearch` delegates to current v3 passage search when retrieval is enabled; legacy v2 mode is retained for regression/comparison. Ranked result IDs are passage IDs; v2 source IDs are citation lineage only. The query selects the configured current retrieval profile, then checks the current source parent/checksum/translation identity before hydration. SQL inputs are bound; query length is 2..200 characters, limit 1..50, page 1..1000, timeout five seconds. Language/category/suitability filters and deterministic ID ordering give a reproducible keyword baseline. There is no stemming, semantic search, relevance reranking or legal inference.

`structured` and `needs_review` CLI filters remain accepted aliases for `eligible` and `review`. Default retrieval admits eligible passages. Review passages require an explicit filter or `all`; future legal-answer policies must independently decide whether review material is suitable. Search returns the real source URL, document/content IDs, language, exact span, original chunk IDs, hash/profile, structural metadata and quality. See [AI_CHUNKING_P3_REPORT.md](AI_CHUNKING_P3_REPORT.md) for measured results and limits.
