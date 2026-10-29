# Mening Fikrim — Lex.uz ingestion

Laravel 13 / PHP 8.5 / PostgreSQL. P1–P3 foundation saqlangan; P4 usable review matnni PostgreSQLda saqlash va verified contentni himoyalashni qo'shadi. [PLAN](docs/LEX_SCRAPING_PLAN.md), [TASKS](docs/LEX_SCRAPING_TASKS.md), [import contract](docs/IMPORT_CONTRACT.md), [P3 hisoboti](docs/P3_REPORT.md), [P4 hisoboti](docs/P4_REPORT.md), [benchmark](docs/SCRAPING_BENCHMARK.md).

## Doimiy database nomlari

- Development: `mening_fikrim_active`.
- Test: `mening_fikrim_active_test`.

Kelgusi bosqichlarda yangi phase bazasi yaratilmaydi. Eski `mening_fikrim_p1` va `mening_fikrim_p1_test` tarixiy holatda saqlanadi. Mavjud boshqa loyiha bazalariga migration/import yozmang. Development bazasida `migrate:fresh` ishlatmang.

PHP 8.5, Composer, PostgreSQL va `pdo_pgsql`, `curl`, `dom`, `mbstring`, XML, fileinfo, openssl kengaytmalari kerak. Poppler: `pdftotext`, `pdfinfo`, `pdftohtml`, `pdftoppm`. Tesseract: `uzb`, `uzb_cyrl`, `rus`, `eng` traineddata. Binary, traineddata, library path va resource limitlar `config/scraping.php` / env orqali sozlanadi. P2/P3 lokal tekshiruvida Tesseract rootless o'rnatilgani uchun `.env`da alohida binary/data path bor; bu pathlarni boshqa muhitga ko'chirmang.

```bash
composer install
cp .env.example .env
```

Yuqoridagi fixed bazalarni yarating, `.env` / `.env.testing` credentiallarini local fayllarda sozlang. Examplelarda credentiallar bo'sh. `.env` va `.env.testing` version controlga kiritilmaydi.

```bash
php artisan key:generate
php artisan migrate --seed
cp .env.testing.example .env.testing
php artisan key:generate --env=testing
composer check-platform-reqs
vendor/bin/phpunit
vendor/bin/pint --test
```

Default test suite tashqi HTTP so'rovlarini taqiqlaydi. Test bootstrap va actual PostgreSQL connection tekshiruvi faqat `mening_fikrim_active_test`ni qabul qiladi. Destructive test migrations shu bazadagina ishlaydi. Bir test bazasida ikkita suite'ni parallel ishga tushirmang. P3ning aniq ruxsat etilgan live sinovi default PHPUnit suite'iga kirmaydi.

## Cheklangan Artisan oqimi

Default `SCRAPING_LIVE_ENABLED=false`, `SCRAPING_SCHEDULER_ENABLED=false`. Hozir faqat **1..30** limitli runlar mumkin. Cheksiz/full va 1000 hujjat importining execution path'i yo'q. `--mode=full` shu cheklangan scope'da dastlabki discovery/import strategiyasini bildiradi.

```bash
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=full --limit=30
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=daily --limit=30
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=recheck --limit=30
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=full --limit=1 --section=int
```

Bir vaqtda **bitta** worker ishlating:

```bash
SCRAPING_LIVE_ENABLED=true php artisan queue:work database --queue=scraping --tries=3 --timeout=900 --stop-when-empty
```

`--stop-when-empty` delayed retry qolganida worker to'xtashi mumkin; retry vaqti yetgach ayni worker buyrug'ini qayta ishga tushiring. `Retry-After`ni chetlab o'tmang. Worker `--timeout=900`, database `retry_after=960`. Source requestlarining umumiy minimal intervali 2 s; discovery/HTML/card/PDF/redirect/retry barchasiga tatbiq qilinadi. Shared database cache HTTP lock barcha workerlarning source requestlarini bir pacerdan o'tkazadi; butun import davomida source lock ushlanmaydi. 403da avtomatik agressiv retry yo'q; 429 cooldown barcha joblarga umumiy.

```bash
php artisan documents:sync lex --report=1
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --resume=1
php artisan queue:failed
```

Resume mavjud durable joblarni ko'paytirmaydi; yo'qolgan unfinished ishlarni tiklaydi. ASP.NET session fresh GET bilan qayta yuriladi; oldingi committed prefix/total proof tekshiriladi. Raw Viewstate/cookie checkpointga yozilmaydi. Terminal quality reviewlar resume bilan valid deb olinmaydi; tuzatilgan parser yoki yangi source ma'lumoti uchun yangi cheklangan run yarating. Standart `queue:retry` xatoni yashirish yoki terminal reviewni chetlab o'tish vositasi emas.

Aniq aralash sample uchun JSON string IDlar ro'yxati (`--limit`dan oshmasin):

```bash
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=full --limit=27 --sample=docs/p2/sample_ids.json
```

Bu explicit selection; uning o'zi discovery coverage dalili emas. Daily oxirgi 7 kunni overlap bilan tekshiradi. Recheck 90 kundan eski/muvaffaqiyatli tekshirilmagan active hujjatlar va effective date'i yetgan pending hujjatlarni tanlaydi. Inactive matnlar o'chirilmaydi. Scheduler 02:00 Asia/Tashkent uchun conditional tayyorlangan, lekin **faollashtirilmagan**.

## Sifat va diagnostika

Metadata effective date ustuvor; matndagi boshqa butun-hujjat sanasi JSONB warning. Har til mustaqil saqlanadi, metadata-only tarjima contents emas. Usable review matn needs_review bilan saqlanadi; missing/unusable til yangi fetch muvaffaqiyati deb olinmaydi. Identity faqat reciprocal source links + rekvizitlar; title, hash yoki abs(id) merge dalili emas.

HTML normative heading/band/superscript va table textni saqlaydi; murakkab span, rasm va matematik superscript review. PDF page count/geometry, OCR va confidence qayd qilinadi, ammo plain text to'liq strukturaviy Markdown deb qabul qilinmaydi. Hozir tasdiqlanmagan PDF/OCR review qoladi. Temporary source/PNG/XMLlar normal, exception va process timeout yo'llarida tozalanadi; bir soatdan eski orphan PDF temp kataloglari keyingi extractionda tozalanadi.

`scrape_run_items` har tanlangan source ID uchun durable holat/proof/review/attempt/receipt saqlaydi. `scrape_runs` checkpointida segmentning joriy page/prefix/total va bounded DFS frontier bor; per-document item/outcome ro'yxati yangi checkpointga yig'ilmaydi. Request loglar daily rotatsiyada; raw body, cookie, token va Viewstate logga yozilmaydi. P3 QA driver ayrim raw javoblarni faqat private `/tmp`da vaqtincha saqlaydi va yakunda tozalaydi; ilova ularni doimiy saqlamaydi.

Limitli run global qamrovni isbotlamaydi: barcha joblar va tillar valid bo'lsa ham `coverage_verified=false`, run `completed` emas. `completed_with_errors` bounded coverage cheklovini ham bildiradi. Hisobotdagi **source status counts** barcha tekshirilgan metadata holatlari, **active/pending/inactive** esa haqiqatan saqlangan documentlar soni.

P4 foydalanuvchi tomonidan aniq tasdiqlangan. 1000 hujjat testi, full import, schedule va keyingi bosqichlar uchun alohida aniq tasdiq talab qilinadi.

## P4 corpus collection va quality recheck

P4 real test:13oldreview guruhning barchasi saqlandi;12needs_review/21text va1sourceHTMLverified/2text. Final DB29document/82identifier/63content, repeat16canonical unchanged,0create/update.130test/581assertion o'tdi; tafsilotlar [P4_REPORT](docs/P4_REPORT.md)da.

Legal status `active/pending/inactive` saqlanadi. Har content rowning mustaqil `quality_status`i verified yoki needs_review, JSONB `quality_issues`, check vaqti va extraction metadata/provenance'i bor. Verified — avtomatik qoidalar o'tdi, inson/yuridik sertifikati emas. Usable PDF/OCR/HTML review matn ham saqlanadi; bo'sh, NUL/invalid UTF-8 yoki basic artifact extraction uchun content row yaratilmaydi, failure run-item/til diagnostikasida qoladi.

Verified text yangi needs_review natija bilan almashtirilmaydi: to'liq nomzod Markdown/hash/issues/provenance ayni content rowdagi `pending_candidate` JSONBda qoladi. Same-language UNIQUE va canonical mapping o'zgarmaydi. Suspected truncation ham eski matnni himoyalaydi. Yangi verified source extraction odatiy safe update qiladi; local recheck source freshnessni yangilamaydi. Document aggregate quality contentlar va ma'lum required tillardan hisoblanadi, yangi document ustuni yo'q.

```bash
php artisan migrate
php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run
php artisan documents:quality-recheck lex --status=needs_review --limit=20
```

Recheck tarmoqqa chiqmaydi, yangi extraction/OCR qilmaydi, matnni o'chirmaydi. Saqlangan extraction dalili yetarli bo'lmasa review saqlanadi. Command authoritative content rowlarni tanlaydi; protected candidate uchun odatiy bounded source re-import kerak. Extraction failurelar bo'sh content sifatida saqlanmagani sabab `--status=extraction_failed`da row0. `--limit=1..30` majburiy chegarasi va meaningful summary bor.

Scraper buyruqlari avvalgidek: yuqoridagi `documents:sync` + bitta database worker. Quality warning jobni fail qilmaydi va collectionni to'xtatmaydi; `--report=RUN_ID`dagi yangi `persisted_imported`, `persisted_unchanged`, `imported_with_needs_review`, `verified` va extraction/technical failure statistikalarini tekshiring. `review_required` ochiq masalalarni bildiradi; saqlangan matn verified degani emas. Explicit source-confirmed inactive sample ham saqlanishi mumkin, default discovery esa active/pending bilan qoladi.

## P3 durable tracking va quality review

Oltita biznes jadval: sources, documents, identifiers, contents, runs va yangi `scrape_run_items`. Migration P2ning bounded itemlarini ledgerga ko'chiradi; eski 24 failed jobni o'chirmaydi. `scrape_runs.failed_count` endi faqat technical failed itemlar, review alohida hisoblanadi. Eski counter diagnostikasi `legacy_failed_count`da saqlanadi. Jadval/constraint va lifecycle [P3_REPORT](docs/P3_REPORT.md)da.

Quality review — normal job natijasi: sabab va har til natijasi itemda saqlanadi, valid tillar commit bo'ladi, invalid matn eski yaxshisini bosmaydi. Job odatiy review sabab retry/failed_jobsga tushmaydi. Kutilmagan parser/DB/process/filesystem exceptioni retry/backoff qiladi, exhausted bo'lsa item failed bo'ladi. Required til texnik xatosida valid tillar commit bo'lishi mumkin, ammo technical failure yashirilmaydi.

```bash
php artisan documents:sync lex --items=6
php artisan documents:sync lex --items=6 --status=review
php artisan documents:sync lex --report=6
```

Bular read-only CLI. `discovered` — bounded run uchun tanlangan source IDlar, canonical groups esa verified identity bo'yicha; stored canonical count alohida. Item queued/processing/retry bilan framework job count bir xil tushuncha emas; report ikkalasini ajratadi. Review soni failed_jobs sonidan olinmaydi. `valid_content_count` shu run'da valid o'tgan unique document/language, `stored_content_count` esa shu documentlarda saqlangan jami content.

Resume faqat unfinished yoki explicit technical failed itemni tiklaydi; terminal review/accepted aliasni avtomatik qayta ishlamaydi. Item attempts — haqiqiy processing claimlari yig'indisi; Laravel attempts — bitta dispatch retry epizodi. Retryda jobs.id yangilansa dispatch_token receiptni saqlaydi. Explicit failed resume yangi epizod beradi, cumulative attempts saqlanadi. Eski failed_jobs tarixiga avtomatik retry/delete yo'q.

Processing lease 930 s, queue retry_after 960 s (job timeout 900 s). Active lease yoki xavfsiz reservation tugamasdan recovery qilinmaydi. Stale/missing receipt explicit resume'da qayta dispatch qilinadi; yangi token eski workerning content/outcome yozishini fence qiladi. Normal worker qayta ishga tushsa expired database queue reservationni ham olishi mumkin. Source row lock faqat qisqa importer transactionida; HTTP/PDF/OCR vaqtida ochiq source transaction yo'q.

## P3 discovery va offline performance

`DiscoveryPlanner::ROOTS` tasdiqlangan olti primary root registry. Sana segmentlari uchun bounded buyruq:

```bash
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=full --limit=30 --section=nat --from=01.01.2010 --to=31.01.2010
```

Source total partition targetdan katta bo'lsa inclusive kalendar oralig'i ikkiga bo'linadi. `discovery_signatures` ledgerda ancestor/leaf unionni dedup bilan tekshiradi. Bir kunda ham limit/cap bo'lsa coverage gap; jim truncate qilib completed qilinmaydi. Query signature barcha filtrlarni hisobga oladi: boshqa yil/til/status/section natijalari drift deb solishtirilmaydi. Actual Viewstate transient, fresh GET replay va prefix proof saqlangan. Global qamrov va undated/root arxiv chegarasi hali isbotlanmagan; CLI 1..30 guardi saqlangan.

`SCRAPING_WORKERS=1` parallelism tavsiyasi, processlarni avtomatik yaratmaydi. Live P3da bitta worker, >=2000 ms; ikki worker/1000 ms faqat `tests/Support/benchmark_worker.php`dagi fake transport bilan sinalgan. Production HTTP floor env bilan 2000 msdan tushmaydi. Shared pacer shu hostdagi ikki processda o'lchandi; turli hostlar clock origin/fairnessi testlanmagan. [Benchmark](docs/SCRAPING_BENCHMARK.md)dagi fixture tezliklari real corpus SLA emas.

## Optional PDF layout diagnostikasi

Poppler asosiy extraction bo'lib qoladi. `inspect_pdf.py` Symfony Process orqali optional `pdfplumber` table/geometry inventarini beradi; default/8 tolerance farqlari review dalili, universal admission emas. `requirements-parser.txt` optional paketni pin qiladi. Alohida Python virtual muhitga o'rnatib `SCRAPING_PYTHON_BINARY`ni uning absolute executable yo'liga, `SCRAPING_PDF_LAYOUT_ENABLED=true`ni sozlang. Examplelarda optional diagnostika o'chirilgan; Poppler/OCR sifat guardlari baribir ishlaydi.

PDF quality levels bytes/page/text/order/structure/language/OCRni ajratadi. Confidence, CER/WER excerptlari, TSV provenance va suspicious regions document-level certification emas. Bo'sh HTML headinglar chiqariladi; complex table katak matni va koordinata/spanlari loss-aware Markdownda saqlanadi, semantikasi tasdiqlanmasa review qoladi. [Manual certification taklifi](docs/P3_REPORT.md)ga qarang. Hozir hech qanday certificate bilan auto-promote endpoint yoki frontend yo'q.


## P5 controlled pilot — bounded stop

P5 topshirig'i up to200NEW canonical documentni30→100→200 gate bilan aniq tasdiqladi. Existing1..30run limit, per-run400request, bitta worker,>=2000ms va disabled scheduling saqlandi. Bir cumulative4000request pilot budget barcha discovery/card/PDF/retry/redirectlarni hisoblaydi;403/429va2consecutive5xx/network error campaignni terminal stop qiladi. Stopped campaignni yangi run/reset bilan chetlab o'tmang, cacheclear qilmang. New tables/migrations/parsers yo'q.

```bash
php artisan documents:pilot status
php artisan documents:sync lex --report=11
php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run
```

Aktiv va to'xtamagan pilot controllerini davom ettirishdan avval stored progress/queue/integrityni tekshiring. Explicit bounded execution driver va yakuniy commands:[p5/README](docs/p5/README.md), [P5_REPORT](docs/P5_REPORT.md). 1000/full/schedule avtomatik boshlanmaydi.


P5 yakuni:[P5_REPORT](docs/P5_REPORT.md),[machine summary](docs/p5/final_summary.json).158NEWcanonical/370newcontent;final187document/433content,344verified+89needs_review.143tests/619assertionsPASS,PintPASS;1229realHTTP,41m37scontroller,0technicalfailedjob.30/100gatespassed,360distinct-source-IDguarddatrafficstop;200exacttarget42taochiq.34pagePDFva7pageexistingTesseractOCR reviewtextsaqlandi. Historicaldata/hashlar saqlangan.1000uchun NO-GO,avvalbounded supplementation/sourceidentity/coveragegate. Hozirgi campaign stopped:execute/resume budgetni avtomatik ochmaydi;no1000/full/schedule.

## P5.1 — 200 NEW canonical pilot completed

[P5.1 report](docs/P5_1_REPORT.md),[evidence and commands](docs/p5_1/README.md):P5 158+P5.1 42=200 NEW canonical. Database229documents/625identifiers/541contents;449verified+92needs_review.414realHTTP,14m42s,0technicalfailure.172tests/719assertions+PintPASS. Original433content hashes/quality and stopped P5 campaign/ledger preserved;no migration/new table.

Availability available/not_available/unknown is independent of content quality; unknown translations are not failures. Source-backed Ruscha/Русча normalization and bounded recursive discovery implemented. Oliy2020:Y fully enumerated1429/1429source IDs,15leaf partitions;global coverage not claimed. Fresh confirmed-absence/failed-translation cases were not encountered;retained/synthetic regressions are labeled. PDF/OCR sample diversity/manual certification and five identity source IDs remain open. P6 CONDITIONAL GO requires separate authorization;current campaign stopped at target,no further live collection scheduled.

```bash
php artisan documents:pilot status
php artisan documents:sync lex --report=45
php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run
```
