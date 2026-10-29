# Portable importer contract — P4 corpus collection

**Current behavior:** P4 below supersedes the historical P1–P3 quality-admission paragraphs. Usable extracted text is persisted as verified or needs_review. Empty/unusable extraction has no content row. Identity/status validation, metadata precedence, independent languages, safe transactions and Strict Completion remain enforced.

`DocumentImporter::import(int $sourceId, array $input, DateTimeInterface $checkedAt): array` faqat normalizatsiya qilingan candidate saqlaydi. HTTP, source HTML selectorlari, metadata label tarjimasi va PDF/OCR extraction adapter/parser javobgarligida. P1 production parser emas. Kirish formati internal trusted service contract; public API mavjud emas.

## Input

```php
[
    'entry_id' => 'opaque-source-id',
    'title' => 'Stable preferred document title',
    'document_type' => 'Act',          // nullable
    'document_number' => '123',        // nullable
    'issued_at' => '2026-10-01',       // nullable ISO date
    'published_at' => '2026-10-02',    // nullable ISO date
    'source_url' => 'https://source.example/doc/opaque-source-id',
    'status_evidence' => [
        'authority' => 'source_metadata',
        'state' => 'active',           // active | revoked | not_in_force
        'effective_at' => '2026-10-03',
        // optional: listing_state; effective_at_candidates (all must agree)
    ],
    'text_effective_at' => '2027-01-01', // optional whole-document body date, warning only
    'identity' => [[
        'external_id' => 'opaque-source-id',
        'language' => 'uz-Latn',
        'source_url' => 'https://source.example/doc/opaque-source-id',
        'availability' => 'full_text', // or metadata_only
        'links' => ['opaque-source-id'],
        'requisites' => [
            'type' => 'Source act category',
            'form' => 'Source act form',
            'bodies' => [['issuer' => 'Adopting institution', 'date' => '2026-10-01', 'number' => '123']],
            // optional registration_number, registration_date, publication_date
        ],
    ]],
    'contents' => [
        'uz-Latn' => [
            'title' => 'Title in this language',
            'content' => "# Title\n\nVerified Markdown body.\n",
            'quality' => [
                'format' => 'html', // html | pdf | ocr
                'complete' => true,
                'structure_verified' => true,
                'issues' => [],
                // pdf/ocr: pages_expected and pages_extracted, positive equal integers
                // ocr: document_certified=true also required
            ],
        ],
    ],
    'metadata' => ['provider_namespace' => ['provenance' => '...']],
]
```

Adapter barcha haqiqiy source til kontrollarini tekshirishi, graphni yopishi, metadata-only va full-textni ajratishi, kartochkadagi statusni ishonchli normalizatsiya qilishi kerak. Generic validator Lex.uz label/selectorlarini bilmaydi. `state=active`ni listingdagi `Y`ning o'zi bilan berish mumkin emas. Global UI tili ham full-text variant mavjudligining dalili emas. `quality.complete=true` uchun shunchaki matn bo'sh emasligi yetarli emas: parser to'liqlik/strukturani tekshirishi shart. Ishonchsiz dalil false/issues bilan kelishi kerak.

## Identity va transaction

Har identity node uchun opaque string ID, language, explicit availability, source links va rekvizitlar kerak. Har linkning targeti va reciprocal linki shu componentda bo'lishi; component connected bo'lishi shart. Mandatory rekvizitlar va ikki tomonda mavjud optional qiymatlar mos keladi. Optional qiymat yo'qligi boshqa ikkita zid qiymatni bog'lab o'tolmaydi. Bir til uchun ikki node — review; tasdiqlanmagan PDF alias auto-merge qilinmaydi. Birinchi component lexical anchor oladi; keyingi verified til qo'shilsa anchor o'zgarmaydi. Entry ID, title, hash va ID ishorasi canonical merge dalili emas.

Source row `FOR UPDATE` lock ostida identifier jadvalidan lookup qilinadi. Ikki documentga urilish, persisted language/requisites conflict yoki oldingi verified identifierlarning yo'qolishi butun candidate uchun fail-closed. Source namespace independent. Composite FK boshqa source documentiga identifier yozishni DB darajasida ham taqiqlaydi. Shu yozish protokolini chetlab o'tgan writerga nisbatan unique/FK kafolatlari qoladi, lekin semantik identity tekshiruvi avtomatik bajarilmaydi.

Har full-text til alohida validatsiya qilinadi. Valid tillar bitta transactionda saqlanadi; invalid tillar xato natijasida qaytariladi va eski matnga tegilmaydi. DB/infrastructure exception bo'lsa transactiondagi barcha yozuv rollback. Metadata/status noma'lum bo'lsa hech qanday yozuv yo'q. Yangi hujjat active/pending va kamida bitta valid matnga ega bo'lishi kerak. Avval mavjud hujjat inactive bo'lishi mumkin, matni saqlanadi.

## Natija va completion

Natija: `document_id`, `action` (`created|updated|unchanged|rejected`), `complete`, language outcomes (`valid|review|metadata_only` va errors), warnings. `complete` faqat documentning shu tekshiruvi uchun; corpus/global completion emas. Faqat `complete=true`da `last_checked_at` yangilanadi. Valid til uchun `last_fetched_at` yangilanadi, hatto hash o'zgarmasa ham. Har til latest bitta row; hash newline-normalized Markdown SHA-256. Timestamp o'zgarishi o'zi `updated` biznes natijasi emas.

Required tilning eski matni borligi yangi fetch failure'ni muvaffaqiyatga aylantirmaydi. Source metadata valid bo'lsa, partial content natijasida ham source status/date va diagnostika yangilanadi; `last_checked_at` o'zgarishsiz qoladi. `-8553692` metadata sanasi `2026-10-23` ustuvor, PDFdagi `2027-01-01` JSONB warning. OCR sifati esa alohida review qoladi. Bu A/B/C qarorining ataylab ko'zlangan xulqi.

Oldingi full successful checkdan eski verification rad etiladi; partial importdagi yangi til fetchini undan eski matn bosmaydi. Saqlangan optional requisites yo'q yangi card bilan o'chirilmaydi. Source yangi graph'dan eski identifierni butunlay olib tashlasa, hozircha avtomatik unlink yo'q — review.

`ScrapeRun::canComplete()` va DB CHECK beshta checkpoint gate'ini (`coverage_verified`, `discovery_finished`, `jobs_reconciled`, `languages_complete`, `quality_passed`) hamda failed_count=0 talab qiladi. Bo'sh queue yetarli emas. P1 live orchestration/checkpoint reconciliation'ni qurmaydi; ushbu flaglarning dalilini kelajak orchestrator hisoblaydi. Import job incomplete natijada validation exception chiqaradi, valid tillar esa committed qoladi. Run counters/progress reconciliation keyingi bosqichga tegishli; importer runni avtomatik completed qilmaydi.

P0.1dagi 30% qisqarish mezoni P1da konservativ vaqtinchalik himoya sifatida qoldi; bu global production threshold yoki full OCR acceptance tasdig'i emas. Butun hujjat OCR certification jarayoni hali kelishilmagan; real P0/P0.1 OCR matnlari P1da certified deb ko'rsatilmaydi. Manual source uchun nullable anchor schema/model bilan qo'llangan; identifiersiz manual authoring interfeysi bu bosqichda qurilmagan.

## P2 source adapter va bounded orchestration

P1 universal importer/validator contracti o'zgarmadi. LexUzScraper real response'lardan shu contractni hosil qiladi; HTTP/selectorlar importerdan tashqarida. Real PDF/OCR hozir structure/language coverage yoki certification yetishmagani sabab review; source active/pending metadata ularni avtomatik valid qilmaydi.

P2 live ImportDocumentJob source ID/run ID bilan candidate oladi. Valid til yozuvlari va terminal run outcome bir PG transactionda; transient extraction failureda valid tillar saqlanib retry qilinadi. Terminal quality review failed_jobsda ham ko'rinadi. Eski P1 array-candidate job interfeysi saqlangan.

ScrapeRunManager faqat 1..30 bounded ledger yuritadi; queue insert/checkpoint ayni DB transactionda. Coverage har doim false, shu bois barcha sample valid bo'lsa ham run global completed emas. Bounded summary failed va reviewni ajratadi; scrape_runs.failed_count ikkalasini qamraydi. Terminal report finished_atni qayta yozmaydi. Bu katta corpus orchestration contracti emas. Real natijalar va cheklovlar [P2_REPORT](P2_REPORT.md)da; P3/full alohida tasdiq bilan.

## P3: accepted, review va technical failure

Universal DocumentImporter/DocumentValidator algoritmi saqlandi. Uning `complete=false` natijasi endi queue exception bo'lishi shart emas: P3 ImportDocumentJob durable `scrape_run_items.review`ga sabab/til natijalarini saqlab normal tugaydi. Invalid til eski matnni bosmaydi; valid boshqa tillar saqlanadi. `last_checked_at` strict guardi avvalgidek.

Known source/quality uncertainty `SourceReviewException` yoki validator errors orqali reviewga tasniflanadi. Kutilmagan RuntimeException, HTTP/process/filesystem/DB xatosi technical bo'lib qoladi. Per-language technical exceptionda mavjud valid tillar commit bo'lishi mumkin; item retry_wait/failed bo'ladi, review deb yashirilmaydi. Partial created action retrydan keyin ham created hisobini yo'qotmaydi. P1 prepared-array jobning successful interfeysi saqlandi; uning review natijasi alohida prepared_candidate scoped run/itemda durable yoziladi.

Normal source runlarida ledger birlamchi hisob manbasi: discovered→queued→processing→imported/unchanged/review/failed/skipped_alias; retry_wait va explicit failed/stale recovery bor. Import va terminal item/counter update bitta transactionda, queue receipt/checkpoint/item yaratish ham bitta transactionda. Validated canonical document shu run'da full checkdan o'tgan bo'lsagina boshqa verified identifier skipped_alias bo'ladi. Oldingi running mappingi o'zi yangi fetchni skip qilishga yetmaydi.

New checkpoint version=3 per-document list yig'maydi. `discovery_signatures` ancestor source-total unionni ledgerda reconciliation qilish uchun; raw Viewstate yo'q. P2 bounded old checkpoint migration orqali ko'chirilgan, uning old failed_count provenance'i saqlangan. Run enum o'zgarmadi; failed_count technical-only, review itemlardan sanaladi. Deferred PG constraint triggers completed run bilan unresolved item/counterni birga qoldirishni ham rad etadi. CLI bounded scope bo'lgani uchun actual coverage_verified=false; bo'sh queue yoki barcha sample validligi global completed emas.

P2dagi metadata=2026-10-23/PDF=2027-01-01 fixture tarixiy precedence testi bo'lib qoladi. P3 actual source metadata 2027-01-01 bo'lganida pending olinadi; IDga xos eski sana hardcode qilinmaydi. Parser/quality diagnostics bo'yicha boshqa source o'zgarishlari [P3_REPORT](P3_REPORT.md)da.

## P4: persistence independent of quality

`ContentQuality` calls the unchanged `DocumentValidator::content`. Its strict errors and original parser issue codes remain available. A separate collection gate rejects missing/blank, malformed UTF-8/NUL, punctuation-only, fewer than10 alphanumeric characters or fewer than4 distinct alphanumeric characters. These are basic artifact guards, not semantic/accuracy certification. Meaningful UTF-8 text containing a replacement character is retained needs_review with `invalid_text`; invalid UTF-8/NUL is not stored.

For every full-text language, outcomes retain `state=valid|review`, `errors`, and add `quality_status`, `quality_issues`, `persistence`, `extracted_hash`, `stored_quality_status`. Persistence is stored/candidate/stale_ignored/extraction_failed. Metadata-only nodes are never content. Source-confirmed inactive documents in an explicit fetch may now be collected with legal status inactive; default active/pending discovery is unchanged. Unknown/contradictory identity or source metadata still blocks association rather than inventing a document/status.

The six-table model remains. Existing `document_contents` adds:

- `quality_status`: verified or needs_review. Verified means existing automated rules passed; not human/legal certification.
- `quality_issues`: JSONB code list including original parser codes and validator errors.
- `quality_checked_at`: automated content-quality check time.
- `extraction_metadata`: source URL, content SHA, retained quality/structure/provenance evidence; fresh Lex variants include HTML SHA and parser/extractor code hashes. Existing PDF/OCR hashes/model/raster diagnostics remain.
- `pending_candidate`: nullable JSONB containing one latest protected candidate's full normalized Markdown, title/hash, needs_review classification, issues/provenance, observed time and protected authoritative hash. This is bounded latest-candidate staging, not version history or a new object store.

`extraction_failed` exists only in extraction/run-item diagnostics; a failed extraction must not create an empty content row. Another valid language or previously stored text is not marked failed. Document `quality_status` is derived: no stored contents→extraction_failed; a known required language missing, any stored needs_review or held candidate→needs_review; otherwise all stored contents verified→verified. This describes stored coverage/quality, not whether the latest source request succeeded.

Update policy under the existing source/document transaction lock:

1. New usable representation: persist verified or needs_review with the existing `(document_id,language)` UNIQUE.
2. Existing verified + new needs_review, including identical text: retain authoritative text/hash/quality/fetch time; stage the candidate and diagnostics. Never downgrade automatically.
3. Suspected >30% shrink of existing text: retain the current text and stage the usable candidate, even if the current text also needs review. The validator guard remains.
4. New verified extraction: normal safe update/promotion. A newer source-verified extraction resolves a held candidate; retain its hash/issues/time/resolution fingerprint.
5. Existing needs_review + new usable needs_review: latest safe non-truncated result updates the same row. No historical versions are promised.
6. Missing/unusable/stale extraction never deletes or replaces stored content. Invalid language outcomes do not cancel another valid/usable language.

Result keeps `document_id/action/complete/languages/warnings`; adds `import_outcome=imported|unchanged|extraction_failed|not_persisted`, latest-attempt `quality_status`, `persisted_content_count`, `persistence_complete`. `not_persisted` denotes usable but stale ignored text. The result's extraction attempt is distinct from the document's stored aggregate. `complete` still requires every required language verified in this fetch; only then `last_checked_at` advances. Review writes advance that representation's `last_fetched_at/quality_checked_at`; protected candidates have their own observed time. Timestamp/provenance-only refresh does not count as a changed legal text.

Run item workflow is unchanged: review remains a normal terminal job state and its content may now be imported successfully. `outcome.import_outcome` separates persistence, `outcome.quality_status` separates quality, and `status` describes execution. `review_required` and Strict Completion remain strict; successful writes never certify quality. Legacy `imported/unchanged` counters describe accepted item states. New `persisted_imported/persisted_unchanged`, `imported_with_needs_review`, `verified`, `extraction_failed`, `failed_content_representations` and stored-needs-review counts expose collection results. Counts are per source item/representation as named, not all canonical group counts; aliases remain separate.

Local recheck: `php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run` and remove dry-run to apply. Status filter/limit1..30, no HTTP/OCR/new source evidence. It reuses the validator and retained extraction evidence; it cannot certify an unverified PDF or resolve a newer held candidate using older trusted text. It preserves source fetch time and never deletes text. Held candidates are re-evaluated through normal bounded source re-import; the local command selects authoritative content rows only. `--status=extraction_failed` selects zero rows by design; failures live in run items. Failed recheck diagnostics remain in extraction_metadata.

Raw HTML/PDF is still transient. Full future re-extraction needs a new source fetch and source may have changed. Local quality recheck is not source freshness verification. Large table/provenance JSON and latest-candidate storage need later scale profiling; P4 does not add 1000/full execution, new parsers or auto scheduling.

## P5.1 availability extension (implemented)

Optional identity-node `availability_evidence` contains `status` (available/not_available/unknown), `type`, `metadata`. `full_text` requires available if explicit evidence is supplied; `metadata_only` can be confirmed absent or unknown, never available. Legacy full_text remains evidence of a known representation; legacy metadata_only alone proves no absence. Source adapters normalize actual labels/notices and retain raw evidence; importer has no source selectors.

Importer stores independent per-language observations in existing document metadata JSONB and a bounded previous observation when evidence changes. Unknown defaults never imply absence. No absent/unknown content row or content-quality value is created. Per-language execution diagnostics expose availability_status/extraction_outcome; legacy extraction_failed diagnostics remain compatible. Metadata-only outcomes have no content quality. Raw originals are still temporary.

`Document::quality_status` derives from stored content and confirmed available extraction coverage; unknown availability alone does not cause review. `language_coverage_status` independently returns complete/incomplete/unknown/no_usable_content. Failure of a newly attempted confirmed language can make document coverage incomplete while preserving its previously verified stored text. Content validator/protected-candidate/identity uniqueness and legal status rules are unchanged.

Checked-at/HTML-proof hash refresh alone is not a business update; JSONB evidence is compared with strict scalar types and unordered object keys. Source status/availability changes may update metadata; absence never deletes existing content. Fresh bounded source import rechecks availability; local quality-recheck does not contact the source or infer translation absence. P5.1 evidence/report documents live and offline coverage gaps.
