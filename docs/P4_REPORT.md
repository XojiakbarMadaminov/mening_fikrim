# P4 — Corpus Collection MVP

2026-10-28, Asia/Tashkent. Laravel13 / PHP8.5 / PostgreSQL. P4 foydalanuvchining aniq topshirig'i bilan bajarildi: usable extracted content quality review sabab tashlab yuborilmaydi. Keyingi bosqich, 1000/full import yoki scheduler ishga tushirilmadi.

## 1. Dastlabki muammo va root cause

P3 download/extractiondan keyin reviewni normal queue natijasi sifatida saqlardi, ammo matnning o'zi saqlanmasdi. `DocumentImporter` faqat `DocumentValidator::content().errors === []` tillarni `$valid` ro'yxatiga qo'shgan; yangi hujjatda bu ro'yxat bo'sh bo'lsa document ham yaratilmagan. PDF order/language, complex table, image va uncertified OCR kabi uncertaintylar usable matnni ham diskard qilgan.

Kod o'zgarishidan oldin yozilgan audit: [INITIAL_FLOW](p4/INITIAL_FLOW.md). Existing parser, universal identity, source metadata precedence, transaction, queue retry va P3 durable tracking qayta qurilmadi.

## 2. Eski va yangi flow

Eski: Discovery → Download → Extraction → strict quality validation → faqat valid tillarni persist → normal review ledger.

Yangi: Discovery → Download → mavjud Extraction → identity/status va basic usability → mavjud strict validator bilan classification → usable verified/needs_review textni yoki himoyalangan candidate'ni atomik persist → execution/persistence/quality outcome → keyingi job.

Quality write oldidan hisoblanadi, chunki verified textni himoyalash qarori kerak. Observable natija talabga mos: quality uncertainty contentni rad etmaydi; technical failure alohida retry/failure; eski verified matn past sifatli candidate bilan almashtirilmaydi.

## 3. Schema va migration

Yagona yangi migration: [`2026_10_28_000007_add_content_quality_tracking.php`](../database/migrations/2026_10_28_000007_add_content_quality_tracking.php). Jami **9 migration Ran**. Development faqat `mening_fikrim_active`, test faqat `mening_fikrim_active_test`. Development reset/fresh/drop bajarilmadi; yangi database yoki yettinchi biznes jadval yo'q.

`document_contents`ga besh field qo'shildi:

| Field | Vazifa |
|---|---|
| `quality_status` | verified / needs_review; default needs_review |
| `quality_issues` JSONB | Original parser va validator reason code list |
| `quality_checked_at` | Avtomatik tekshiruv vaqti |
| `extraction_metadata` JSONB | Source URL, quality/structure/provenance, source/extraction hashes |
| `pending_candidate` nullable JSONB | Bitta latest protected candidate: to'liq Markdown/title/hash/issues/provenance/observed time |

`(quality_status,id)` index; status/JSON types, verified→empty issues va usable candidate shape CHECKlari bor. SQL NULL loophole ham constraint testi bilan yopilgan. Existing content FK va `(document_id,language)` UNIQUE o'zgarmadi. JSONB staging trusted matn va past sifatli nomzodni alohida saqlashga yetarli bo'ldi; yangi table/storage subsystem kerak bo'lmadi.

Backfill oldingi per-language admission proof valid bo'lsagina verified beradi; dalilsiz legacy row needs_review + `legacy_quality_unproven`. Haqiqiy developmentdagi **40/40** content valid proof bilan verified bo'ldi. Migration vaqtida oldingi document/source/identifier/content/run/item va 24 failed jobning **barcha eski fieldlari row hash bo'yicha aynan saqlandi**. [Preservation audit](p4/migration_preservation.json), [migration status](p4/development_migration_status.txt).

Migration down developmentda data bo'lsa quality/candidate dalilini tashlab yuborishni rad etadi. Rollback/reapply faqat isolated test DBda tekshirildi.

## 4. Quality va legal status

- **verified:** existing automated quality rules o'tdi; inson/yuridik sertifikati yoki100% aniqlik kafolati emas.
- **needs_review:** usable extracted text saqlangan, ammo quality qoidasi yoki uncertainty ochiq. Original codes yo'qolmagan.
- **extraction_failed:** usable matn olinmadi; run item/til diagnostikasida. Empty content row yaratilmaydi, shu sabab content table status CHECKida bu qiymat yo'q.

Legal `active/pending/inactive` enum/resolution o'zgarmadi. P4 all-extractable talabiga muvofiq explicit fetchdagi source-confirmed inactive sample ham saqlanadi; default discovery active/pendingligicha. Unknown/contradictory identity yoki legal metadata taxmin bilan to'ldirilmaydi.

Document aggregate `Document::quality_status` orqali derived: stored content yo'q→extraction_failed; required til yetishmasa yoki stored review/pending candidate bo'lsa→needs_review; qolgan barcha stored variantlar verified→verified. Bir til o'tgani boshqa tilni verified qilmaydi. Bitta yangi extraction failure boshqa mavjud yaxshi tilni/documentni extraction_failedga aylantirmaydi.

## 5. Verified textni himoyalash

Mavjud source/document lock va transaction ichida:

1. Missing usable variant verified yoki needs_review bilan yangi rowga yoziladi.
2. Existing verified + needs_review, hash bir xil bo'lsa ham, authoritative text/hash/status/fetch time o'zgarmaydi; full nomzod pending_candidate'da qoladi.
3. Suspected30%+ shrink oldingi review textni ham himoyalab candidate'ga o'tadi; validator guard olib tashlanmadi.
4. Yangi verified source extraction normal safe update/promotion qiladi; hal qilingan candidate hash/issues/time va resolution fingerprint metadata'da qoladi.
5. Existing needs_review + safe non-truncated needs_review shu latest rowni yangilashi mumkin. Tarixiy versiyalar arxivi yo'q; faqat bitta latest candidate saqlanadi.
6. Missing/unusable yoki authoritative fetch/held candidate'dan eski natija matnni o'chirmaydi, ustiga yozmaydi va yangi candidate'ni resolve qilmaydi.

Hash newline-normalized Markdown SHA-256. Normalized text/quality o'zgarmasa duplicate row yoki biznes text update yaratilmaydi. Source fetch va quality-check vaqtlarini yangilash alohida; local recheck source fetch vaqtini ko'tarmaydi.

Live repeatda PDF text/hash o'zgarmasdan5ta updated ko'rindi. JSONga saqlashda `0.0/100.0`→`0/100` round-trip va strict scalar metadata comparison sabab controlled regressionda qayta hosil qilindi. Metadata endi Eloquent saqlaydigan JSON shakliga keltirib solishtiriladi; **identity/requisite comparator yumshatilmadi**. Timing/provenance refresh text change sifatida sanalmaydi. [Failing regression](p4/numeric_diagnostic_failure.txt), yakuniy testda ushbu case o'tgan.

## 6. Failed extraction va provenance

Missing/blank, invalid UTF-8/NUL, punctuation-only yoki juda kichik/repeated-glyph artifact contentga aylanmaydi. Minimal usability: kamida10 alphanumeric character va4 distinct character. Bu semantic accuracy proof emas; juda qisqa haqiqiy matn uchun konservativ cheklov. Meaningful UTF-8 ichidagi replacement character matnni yo'qotmaydi: `invalid_text` bilan needs_review saqlanadi. Metadata-only translation notice hech qachon content emas.

Per-language outcome `quality_status=extraction_failed`, original errors va persistence failure bilan durable itemda qoladi. Unexpected parser/process/HTTP/DB xatolari avvalgidek Laravel retry/exhaustion; normal review failed_jobsga tushmaydi. Failed recheck eski textni o'chirmaydi, `last_failed_recheck` diagnostikasini saqlaydi.

Reuse qilingan title/content/hash/language/timestamps bilan birga source URL, source HTML SHA, parser class/code SHA va PDF extractor code SHA yoziladi. Existing PDF/OCR source/Markdown/TSV/model/raster/structure dalili extraction_metadata'da saqlanadi. Raw HTML/PDF saqlanmaydi; kelajakda to'liq extractionni qaytarish yangi fetch talab qiladi va source o'zgarishi mumkin.

## 7. Run va item accounting

P3 state machine, receipt/token/lease/retry va Strict Completion saqlandi. Review itemda endi `document_id` bo'lishi va uning matni muvaffaqiyatli imported bo'lishi mumkin.

Outcome: `import_outcome=imported|unchanged|extraction_failed|not_persisted`, `quality_status`, `persisted_content_count`, `persistence_complete`; har tilda persistence/stored_quality_status/extracted_hash/issues. `not_persisted` usable ammo stale ignored natija uchun. Execution `status=review` technical failure emas.

CLI legacy `imported/unchanged` accepted-item counterlarini saqlaydi; collection uchun **persisted_imported/persisted_unchanged**ni o'qing. `imported_with_needs_review`, `verified`, extraction-failed item/representation, stored-needs-review count ham alohida. Aliaslar alohida sanaladi; source-ID count canonical document count emas. Required-language quality failure hali ochiq gate; collection write quality proof emas. Bounded runlar completed_with_errors, coverage=false.

## 8. Exact commands

Project rootdan:

```bash
php artisan migrate
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=full --limit=13 --sample=docs/p4/review_ids.json
SCRAPING_LIVE_ENABLED=true php artisan queue:work database --queue=scraping --tries=3 --timeout=900 --stop-when-empty
php artisan documents:sync lex --report=10
php artisan documents:sync lex --items=10 --status=review
php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run
php artisan documents:quality-recheck lex --status=needs_review --limit=20
```

Yangi sync yaratgan run IDni terminaldan oling;10 ushbu P4 final run IDsi. `--mode=full` mavjud bounded initial strategy nomi, cheksiz crawl emas. Live1worker/≥2000ms, limit1..30 saqlandi. Delayed retry bo'lsa retry vaqti yetgach worker qayta ishlatiladi; backoff chetlab o'tilmaydi.

Recheck network-free: retained text + existing validator + saved extraction evidence. Faqat requested source/status, limit1..30, dry-run writes0. Unverified PDF/OCRni yangi dalilsiz verified qilmaydi, newer held candidate'ni eski trusted text asosida resolve qilmaydi. Command authoritative rowsni tanlaydi; candidate uchun normal bounded source re-import. Extraction failures bo'sh content row bo'lmagani sabab status=extraction_failed tanlovida0row; diagnostika `--items`da.

## 9. Tests va migration compatibility

Baseline: **110 test /443 assertion PASS**. Yakuniy Laravel/real PG suite: **130 test /581 assertion PASS**, failures/errors/skipped0, terminal36.934s. Yangi20ta corpus testi va oldingi parser/validator/discovery/concurrency/queue regressiyalari birga ishladi. [Final tests](p4/final_tests.txt), [JUnit](p4/final_junit.xml).

P0 offline **16 test PASS**, P0.1 offline **37 PASS** alohida qayta bajarildi; source network yo'q, P0 fault server localhostda. [P0](p4/p0_regression.txt), [P0.1](p4/p01_regression.txt). Laravel default suite `preventStrayRequests` va actual test-DB name guard bilan ishlaydi. Destructive lifecycle faqat isolated test DBda; development ma'lumotlari tozalanmadi.

Coverage: all-review/verified/mixed persistence, issue JSON, empty/meaningless/NUL/replacement text, durable empty failure, aliases/uniqueness, protected same/different-hash candidate, candidate stale fencing, safe promotion, failed reprocessing, status independence, bounded dry-run/filter/repeat/fetch-time preservation, populated legacy migration/unproven proof, PG constraints va JSON numeric idempotence. Existing real two-process source/item races va transaction faultlar ham o'tdi.

To'rtta oldingi assertion P4ning aniq yangi behavioriga moslashtirildi: usable invalid-quality til endi saqlanadi; known prior-good synthetic fixture verified deb aniq belgilanadi; explicit inactive initial collection ruxsat etiladi va ledgerda imported bo'ladi. Guardlar olib tashlanib test soxta PASS qilinmadi.

Intermediate failurelar loglarda saqlandi: fixture is_active/disconnected proof, PostgreSQL `?&` operatorining PDO placeholder sifatida talqin qilinishi (jsonb_exists_allga almashtirildi), yangi constraint testida missing DB import va numeric round-trip regression. Final130/581 bular tuzatilganidan keyin olindi. Formatter/Composer/platform/syntax natijalari [p4 index](p4/README.md)da.

## 10. Haqiqiy import

Kampaniya: oldingi13review +3verified control group +3same-group alias = **19 source ID /16 canonical group**. Birinchi run8: **13 created,3 updated,0 technical failed**; barcha13target document normal importer orqali saqlandi. Selected32 content:11verified/21needs_review. Source formats **11HTML/5PDF**, P4da natural scan OCR extraction ishlatilmadi; eski OCR/parser regressiyalari saqlandi.

| Oldingi review source | P4 document ID | P4 stored quality | Full-text tillar soni |
|---|---:|---|---:|
| [8548754](https://lex.uz/uz/docs/8548754) |17|needs_review|2|
| [8554309](https://lex.uz/uz/docs/8554309) |18|needs_review|2|
| [-7655343](https://lex.uz/uz/docs/-7655343) |19|needs_review|4|
| [-8540314](https://lex.uz/uz/docs/-8540314) |20|needs_review|2|
| [-8102878](https://lex.uz/uz/docs/-8102878) |21|needs_review; legal inactive|2|
| [8557098](https://lex.uz/uz/docs/8557098) |22|needs_review|1|
| [8554315](https://lex.uz/uz/docs/8554315) |23|verified; current source HTML|2|
| [8554432](https://lex.uz/uz/docs/8554432) |24|needs_review|1|
| [8554353](https://lex.uz/uz/docs/8554353) |25|needs_review|1|
| [8554326](https://lex.uz/uz/docs/8554326) |26|needs_review|1|
| [8554321](https://lex.uz/uz/docs/8554321) |27|needs_review|1|
| [-8553258](https://lex.uz/uz/docs/-8553258) |28|needs_review; current HTML|2|
| [-8553692](https://lex.uz/uz/docs/-8553692) |29|needs_review; current HTML|2|

**13/13 old review guruh saqlandi:12needs_review/21text +1verified/2text.** `8554315` P3da PDF edi, P4da genuine2HTML full-text keldi va existing automation o'tdi. Bu yangi PDF extraction texnologiyasi yoki sun'iy threshold pasayishi emas, source format o'zgarishi. Verified inson/yuridik certification degani emas. Har tilning code/provenance/hash/URLsi [review results](p4/review_results.json)da.

Development: **29 documents /82 identifiers /63 contents**, shundan **42verified/21needs_review**. Original40textdan hech biri shikastlanmadi. No empty/fabricated content, new technical failed job0; old24failed_jobs saqlandi.

## 11. Repeat va local recheck

Run9da all63content ID/hash teng, create0, lekin5PDF metadata updated va pending control8548643 source graph `Conflicting component requisites: type` review berdi. Original pending document16 va3verified text saqlandi, guard bypass qilinmadi. Exact failing raw card saqlanmaganligi uchun o'sha source ziddiyatining root cause'i isbotlanmagan. Keyingi run uchun parsed metadata observations alohida saqlandi; source uncertainty success deb ko'rsatilmadi.

JSON numeric metadata comparison tuzatilgach ayni19source-ID run10da yana tekshirildi. Yakuniy aniq natijalar [final CLI summary](p4/final_summary.txt) va [campaign summary](p4/campaign_summary.json)da; oldingi5updated yoki rejected card yakuniy PASSga yashirin qo'shilmagan.

| Collection ko'rsatkichi | Run8 | Final run10 |
|---|---:|---:|
| Discovered source IDs / canonical groups |19 /16|19 /16|
| Imported, `persisted_imported` |16 (13created+3updated)|0|
| Unchanged, `persisted_unchanged` |0|**16**|
| Imported/retained with needs_review |12|12|
| Verified groups in this check |4|4|
| Extraction failed / technical failed |0 /0|0 /0|
| Skipped aliases |3|3|
| Duration |193s|193s|

Final run10: **0create/0update**, legacy accepted-unchanged4 + persisted-review-unchanged12 =16canonical group unchanged. All63ID/hash teng. Control8548643 fresh3cardda type/form/requisites mos keldi; original pending document16 saqlandi. [Parsed card observations](p4/control_card_observations.jsonl) final fresh dalil, run9 ziddiyatining sababini retroaktiv isbotlamaydi.

Local recheck: dry-run limit20 →20selected; apply/repeat limit30 →**21checked,21needs_review,0verified,0extraction_failed,0changed**, ikkala urinishda. All63ID/hash va source-fetch timestamp teng. [Recheck integrity](p4/recheck_integrity.json), [apply](p4/recheck_apply.txt), [repeat](p4/recheck_repeat.txt). Live verified→review downgrade tabiiy ravishda uchramadi; shu himoya controlled PostgreSQL testlari bilan isbotlandi.

## 12. Preservation va yakuniy audit

Original40 content ID/language/document association/hash, all63first-import hash/id, old identifier IDlar va24failed_jobs row hashlarini [preservation.json](p4/preservation.json) tekshiradi. P0–P3ning433historical file hashini [historical integrity](p4/historical_integrity.json) tekshiradi. Core validator, HTTP pacer, parser/PDF/OCR modules, job va item tracker o'zgarmagan.

Jami **286real request /286×200**, minimal start interval **2.000029156s**, retries0, shared campaign budget400. Birinchi22:46:31–oxirgi23:02:22 (+05),951.270s wall window; ichida operator/kod/test pauzalari ham bor. Bu continuous throughput benchmark emas. Real403/429/5xx/timeout kuzatilmadi, server rate limiti aniqlangani da'vo qilinmaydi. P4live1worker va16distinct group bilan cheklandi. [HTTP stats](p4/http_stats.json), [terminal](p4/final_terminal.txt).

Final DB:29documents/82identifiers/63contents,10runs/169items,0jobs,24oldfailed_jobs. Raw source capture qilinmadi; temporary PDF/PNG normal finally orqali tozalangan. Default live/schedulerfalse, worker1/delay2000ms; `.env` fayllari0600. Migrations/test/formatter/cleanup dalillari [p4/README](p4/README.md)da.

Implementation/test o'zgarishlar: new migration, `ContentQuality`, `DocumentImporter`, `DocumentContent/Document`, `LexUzScraper` provenance, `ScrapeRunManager` summary, `RecheckDocumentQuality`, `CorpusCollectionTest` va ikki existing feature test. To'liq [changed files](p4/changed_files.json). `ImportDocumentJob`/`RunItemTracker`ni qayta yozish kerak bo'lmadi. README/PLAN/TASKS/IMPORT_CONTRACT/AGENTS yangilandi; tarixiy hisobotlar tahrirlanmadi.

## 13. Qolgan cheklovlar

- 21needs_review contentning table/image/math/PDF language/order masalalari saqlanadi. Matnni saqlash quality yoki legal correctnessni sertifikatlamaydi; document-level OCR certification qo'shilmadi.
- Source identity/card ziddiyati hali associationni xavfsiz bloklashi mumkin; bu quality uncertainty bilan teng emas. Yaxshi oldingi data o'chirilmaydi, diagnostika qoladi.
- Raw originals saqlanmaydi; local recheck yangi source/layout dalilini yaratmaydi. Held candidate local authoritative recheck bilan auto-promote qilinmaydi.
- Bitta latest content/candidate, to'liq version history yo'q. Juda qisqa legitim text uchun usability guard konservativ; metadata/candidate storage scale profiling P4da bajarilmadi.
- Global discovery/undated/cap/expiry/bulk access/1000gate va uzoq muddatli resilience isbotlanmagan. Live rate/concurrency oshirilmadi.

## 14. Keyingi qadam

P4ning collection maqsadi bajarildi: usable review text PostgreSQLda saqlanadi, quality alohida va diagnostics yo'qolmaydi, verified text himoyalanadi, recheck mavjud. Keyingi alohida tasdiqlanadigan ish — bounded corpus coverage va manual quality QA/reprocessing mezonlari. **1000/full import, scheduler/deployment va keyingi bosqichlar boshlanmadi; alohida aniq ruxsat kerak.**
