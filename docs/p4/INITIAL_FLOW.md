# P4 initial inspection — before implementation

Baseline: 110 tests /443 assertions PASS on the fixed PostgreSQL test database. Existing foundation is retained.

Discovery (`LexUzScraper::discover` → `ScrapeRunManager`) → atomic durable item/queue dispatch → `ImportDocumentJob` → `LexUzScraper::fetch` → `LexUzParser` / existing Poppler/Tesseract → portable candidate → `DocumentImporter` → `DocumentValidator::content` → PostgreSQL.

The exact persistence block is in `app/Services/Documents/DocumentImporter.php`: only languages with `result.errors === []` enter `$valid`. If a new document has no such language, the importer returns `action=rejected, document_id=null`. Only `$valid` is written to `document_contents`. Therefore even nonempty extracted Markdown with `quality_review`, complex table, PDF order/language or uncertified OCR issues is discarded. P3 correctly records review in run items and avoids failed_jobs, but does not retain this extracted text.

Other independent guards remain: reciprocal identity/requisites, source metadata/date resolution, stale fetch, empty/invalid text, source namespace and source lock, safe update, strict completion. The initial-inactive guard also blocks the explicitly listed -8102878 sample. P4's all-extractable objective will admit explicitly fetched, source-confirmed inactive documents with usable text while leaving default discovery active/pending and legal status resolution unchanged.

Chosen minimal path: add content-level quality fields, extraction metadata and one pending review candidate JSONB on the existing content table; no seventh business table. Existing content with recorded valid-language admission proof is backfilled verified; unproven rows remain needs_review. Existing text/IDs/hashes are unchanged. Failed extractions have no empty content row and stay in per-language run diagnostics. A lower-confidence candidate cannot overwrite verified text; retain its Markdown/hash/issues/provenance in pending_candidate. Recheck is local, bounded and network-free; it cannot invent missing source evidence or certify OCR. Strict Completion remains strict even though collection no longer blocks on review.

P4 is explicitly authorized by the user's Corpus Collection MVP task. Live verification remains one worker, >=2000ms, <=30 groups, shared400-request campaign budget. No 1000/full/cron/AI/new OCR technology.
