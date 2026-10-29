# Hujjatlar importi — texnik topshiriq va arxitektura

**Loyiha:** Mening Fikrim — universal document ingestion (1-bosqich: Lex.uz)  
**Kelishilgan sana:** 2026-10-28  
**Holat:** P5 controlled pilot safety budgetda yakunlandi:158NEW canonical,30/100gatePASS,exact200open;19-bo'lim va P5_REPORT.  P0–P4 dalillari saqlanadi. P0–P3 yakunlangan. P4 Corpus Collection MVP aniq tasdiqlandi: usable review contentni saqlash, mustaqil content quality va verified text himoyasi. P4ning quyidagi18-bo'limi tarixiy strict persistence qoidalarini almashtiradi. 1000/full/schedule va keyingi bosqichlar alohida tasdiq bilan.  
**Asosiy qoida:** P1da noldan yaratilgan Laravel 13 foundation saqlanadi; P2 uni kengaytiradi, loyiha qayta yaratilmaydi. Eski application kodi ishlatilmaydi. Hozirgi ish faqat scraping va hujjatlarni saqlash.  

## 1. Maqsad

Laravel 13 va PostgreSQL asosida hujjatlar bazasini yaratish. Birinchi manba — Lex.uz; keyinchalik boshqa saytlar va qo'lda kiritilgan hujjatlar ham aynan shu modelda saqlanishi kerak. Avval amaldagi va hali kuchga kirmagan barcha turdagi hujjatlarning joriy matnlari import qilinadi. Keyin yangi hujjatlar, matndagi o'zgarishlar va huquqiy holat kunlik va davriy tekshiriladi.

### V1 doirasiga kiradi

- Universal hujjat sxemasi va Lex.uz adapteri.
- Lex.uz'dagi **barcha hujjat turlaridan `active` va `pending`** bo'lganlarini dastlabki import qilish.
- Har bir hujjatning **barcha manbada mavjud bo'lgan tillarini** alohida saqlash (`uz-Latn`, `uz-Cyrl`, `ru`, `en` va uchrasa boshqa tillar).
- **Faqat joriy/latest tahrir**; eski tahrirlarning tarixini ko'chirmaslik.
- HTML, PDF text-layer va OCR fallback bilan matnni olish.
- Tozalangan Markdownni PostgreSQL `TEXT`da saqlash; asl HTML/PDFni doimiy saqlamaslik.
- Dastlabki import, kunlik discovery, o'zgarishni aniqlash, 90 kunlik rolling recheck.
- Laravel Database Queue, checkpoint, retry, quality validation, monitoring, strict completion.
- Terminal komandalar, testlar, operatsion README.

### V1 doirasiga kirmaydi

- LLM, Ollama, embedding, pgvector, chunking, RAG, semantik izlash.
- Frontend, API, admin panel, autentifikatsiya.
- Tarixiy tahrirlarni arxivlash, manbalararo semantik deduplikatsiya.
- Importdan oldin kuchini yo'qotgan **barcha tarixiy hujjatlarni** ataylab qidirib yuklash.
- Har bir qonun normasining alohida modda darajasidagi amal qilish statusini yuridik modellashtirish.

## 2. Tasdiqlangan qarorlar

| # | Qaror |
|---|---|
| 1 | Yangi Laravel 13, PHP 8.5, local PostgreSQL; AI keyinroq |
| 2 | Baza Lex.uz'ga qattiq bog'lanmaydi; boshqa saytlar va manual importga mos |
| 3 | Barcha turdagi active/pending hujjatlar, barcha mavjud tillar; ikki qatlamli `documents` + `document_contents` |
| 4 | Hujjatning joriy matni tozalangan Markdown; chunks va embeddinglar hosila ma'lumot sifatida keyinroq |
| 5 | Laravel Database Queue |
| 6 | Hybrid Sync: daily discovery, aniqlangan o'zgarishlar, active hujjatlar 90 kunlik rolling recheck |
| 7 | Minimal Validator + Quality Check, 30 va 1000 hujjatlik sinov |
| 8 | P3 tasdiqlagan 6 ta asosiy biznes jadvali (scrape_run_items bilan); frameworkning jobs/failed_jobs/cache infrastruktura jadvallari bundan tashqari |
| 9 | Modular Monolith + Lightweight Services; HTML parser, PDF parser, Tesseract OCR fallback |
| 10 | `active` + `pending` dastlabki import; keyinchalik `inactive` bo'lsa matni saqlanadi |
| 11 | Conservative Start: 1 worker, umumiy 2 soniya request interval, `.env` boshqaruvi |
| 12 | Strict Completion: discovery, queue, validatsiya tugashi va ochiq xato qolmasligi kerak |

## 3. Muhim biznes qoidalari

1. **Yangi hujjat:** odatiy discovery active/pending; P4da explicit fetch qilingan source-confirmed inactive hujjat ham usable content bilan saqlanishi mumkin (18-bo'lim).
2. **Hujjat yangi tahriri:** `document_contents.content` va `content_hash` oxirgi tekshirilgan to'g'ri matn bilan yangilanadi; tarixiy snapshot yaratmaymiz.
3. **Hujjat kuchini yo'qotsa:** ilgari import qilingan `documents.status = inactive`; content o'chirilmaydi.
4. **Hali kuchga kirmagan hujjat:** `pending`; kuchga kirish sanasi yetganda manba holati qayta tekshiriladi, shundagina active o'tadi. Ayrim normalar boshqa sanada kuchga kirishi mumkin: v1 faqat **hujjat darajasidagi umumiy status**ni beradi.
5. **Manbada vaqtincha yo'qolish, 404, 403, 429 yoki parse xatosi:** hujjatni avtomatik inactive qilish uchun yetarli emas; eski ma'lumotni saqlash va xatoni kuzatish kerak.
6. **Tillar:** faqat manbada haqiqatan mavjud bo'lgan variantlarni olish; avtomatik tarjima qilmaslik. Hujjatning mavjudligi tasdiqlangan tilini import qila olmaslik — incomplete/error; umuman taklif qilinmagan til — error emas.
7. **Oldingi tahrirlar:** eski tahrirlarga UI havolalari joriy matnga qo'shilmaydi. LexUZ sharhlari asosiy normativ matndan ajratiladi, v1 da unga aralashtirilmaydi.
8. **Qo'lda kiritish:** `manual` manbali hujjat, `external_id = NULL`; kelajakda ayni `DocumentImporter` ishlatiladi. V1 da manual API/UI qurilmaydi.
9. **RAG xavfsizligi:** v2 da faqat `active` va tegishli sanaga amalda bo'lgan ma'lumotlar retrievalga kiradi; hozir RAG implementatsiyasi yo'q.

## 4. Texnologiyalar

- Laravel 13 / PHP 8.5, Composer.
- PostgreSQL (mavjud local instance), Laravel Eloquent va migrations.
- HTTP: Laravel HTTP Client; HTML: Symfony DomCrawler + CSS selector yordamchisi.
- PDF: Poppler `pdftotext`; faqat text layer yaroqsiz yoki skaner sahifalarda `Tesseract OCR` fallback, sahifalarni vaqtincha qayta ishlash.
- Background: Laravel `database` queue va Laravel Scheduler; `CACHE_STORE=database` locklar uchun.
- Test: Laravel HTTP mocking + Pest yoki PHPUnit (loyiha boshlanganda bitta standart tanlash).
- Yangi infra sifatida Redis, Qdrant, Docker, Filament, browser automation va OCR tashqi servis majburiy emas.

## 5. Minimal database — P3dan boshlab 6 ta asosiy jadval

### 5.1 `document_sources`

| Field | Type | Izoh |
|---|---|---|
| `id` | bigint PK | |
| `name` | varchar | `Lex.uz`, `Manual` |
| `slug` | varchar UNIQUE | `lex`, `manual` |
| `type` | varchar | `scraper`, `manual` |
| `base_url` | varchar NULL | URL manba uchun |
| `is_active` | boolean | Scraper manbasini yoqish/o'chirish |
| `created_at`, `updated_at` | timestamps | |

### 5.2 `documents`

| Field | Type | Izoh |
|---|---|---|
| `id` | bigint PK | Barqaror ichki document ID |
| `source_id` | FK | `document_sources.id` |
| `external_id` | varchar NULL | O'zgarmas boshlang'ich canonical anchor; aliaslar `document_identifiers`da |
| `title` | TEXT | Asosiy title |
| `document_type` | varchar NULL | Hujjat turi |
| `document_number` | varchar NULL | Hujjat raqami |
| `status` | varchar | `active`, `pending`, `inactive` |
| `issued_at` | date NULL | Qabul qilingan sana |
| `published_at` | date NULL | E'lon qilingan sana |
| `effective_at` | date NULL | Umumiy kuchga kirish sanasi |
| `source_url` | TEXT NULL | Asl havola |
| `metadata` | JSONB | Faqat manbaga xos qo'shimcha ma'lumotlar; `{}` default |
| `last_checked_at` | timestamp NULL | Oxirgi **muvaffaqiyatli** status + matn tekshiruvi |
| `created_at`, `updated_at` | timestamps | |

Constraints: FK, UNIQUE `(source_id, external_id)`. PostgreSQL bir necha `NULL` external_id'ga ruxsat beradi — manual uchun maqsadli. Status DB CHECK va ilova qoidalari bilan, source metadata esa validator bilan tekshiriladi.

### 5.3 `document_identifiers`

| Field | Type | Izoh |
|---|---|---|
| `id` | bigint PK | |
| `document_id` | FK | Barqaror `documents.id` |
| `source_id` | FK | `document_sources.id` |
| `external_id` | varchar | Opaque source ID, ishorasi saqlanadi |
| `language` | varchar NULL | BCP47 |
| `source_url` | TEXT NULL | Variant manzili |
| `metadata` | JSONB NULL | Reciprocal links, rekvizitlar, availability/provenance |
| `created_at`, `updated_at` | timestamps | |

UNIQUE `(source_id, external_id)`; INDEX `document_id`; FK source va documentga. Composite FK `(document_id, source_id)` boshqa source hujjatiga alias biriktirishni ham to'sadi. JSONB alias qidiruvi asosiy uniqueness mexanizmi emas. Identity faqat o'zaro source til havolalari va mos rekvizitlar bilan tasdiqlanadi; `abs(id)`, title va hash merge dalili emas. `documents.id` universal ID; bir marta saqlangan `documents.external_id` canonical anchor keyingi variantlarda o'zgarmaydi. P1 yangi component uchun opaque IDlarning lexical minimumini tanlaydi.

### 5.4 `document_contents`

| Field | Type | Izoh |
|---|---|---|
| `id` | bigint PK | Kelajak chunklarning FK manbasi |
| `document_id` | FK | `documents.id` |
| `language` | varchar | BCP47 ko'rinishida (`uz-Latn` va h.k.) |
| `title` | TEXT | Shu tildagi nom |
| `content` | TEXT | Tozalangan Markdown |
| `content_hash` | char(64) | Normalizatsiya qilingan Markdownning SHA-256 |
| `last_fetched_at` | timestamp NULL | O'sha til bo'yicha so'nggi muvaffaqiyatli fetch |
| `quality_status` | varchar | P4: verified / needs_review, legal statusdan mustaqil |
| `quality_issues` | JSONB | P4: parser va validator code list |
| `quality_checked_at` | timestamp NULL | P4: avtomatik sifat tekshiruvi |
| `extraction_metadata` | JSONB | P4: source URL/hash, extraction/quality/provenance dalili |
| `pending_candidate` | JSONB NULL | P4: protected lower-quality latest candidate Markdown va diagnostikasi |
| `created_at`, `updated_at` | timestamps | |

Constraint: UNIQUE `(document_id, language)`; har tildan bitta authoritative content, zarur bo'lsa bitta latest pending candidate. Noto'g'ri PDF/OCR avvalgi verified contentni almashtirmaydi. Empty extraction row emas; extraction_failed run-item/til diagnostikasida.

### 5.5 `scrape_runs`

| Field | Type | Izoh |
|---|---|---|
| `id` | bigint PK | Import sessiyasi |
| `source_id` | FK | |
| `mode` | varchar | `full`, `daily`, `recheck` |
| `status` | varchar | `running`, `completed`, `completed_with_errors`, `failed` |
| `processed_count` | integer default 0 | Yakuniy qayta ishlangan ishlar |
| `created_count` | integer default 0 | Yangi hujjatlar |
| `updated_count` | integer default 0 | Yangilanganlar |
| `failed_count` | integer default 0 | Yakuniy xatolar |
| `checkpoint` | JSONB NULL | Qidiruv bo'limi, davr va keyingi page/cursor |
| `last_error` | TEXT NULL | Oxirgi muhim xato |
| `started_at`, `finished_at` | timestamps | |

**P1 tasdiqlangan schema:** faqat `processed_count`, `created_count`, `updated_count`, `failed_count`. Avval rejalangan discovered/queued/unchanged ustunlari qo'shilmadi. Kelajak orchestration reconciliation dalillari checkpoint/framework batch orqali loyihalanadi; katta per-document ledgerni JSONBga joylash tasdiqlangani yo'q. `jobs`, `failed_jobs`, `cache`, `cache_locks`, `job_batches`, `migrations` — framework infrastruktura jadvallari. Per-document javobgarlik ishonchsiz bo'lsa `completed` taqiqlanadi.

### 5.6 `scrape_run_items` — P3da tasdiqlangan durable ledger

Har run/source ID uchun bitta row: UNIQUE `(scrape_run_id, source_external_id)`. ID opaque string; `abs()`/integer normalizatsiya yo'q. `document_id` nullable, chunki graph/sifat/admission tasdiqlanmagan bo'lishi mumkin. Run FK cascade, document FK restrict; importer document o'chirmaydi.

Asosiy fieldlar: run/source external ID, nullable document, status, cumulative attempts, review_reasons/language_results JSONB, last_error, started/finished/created/updated timestamps.

Minimal texnik qo'shimchalar: `listing_status` (source corroboration), `action` (created/updated/unchanged accounting), `outcome` JSONB (per-item diagnostic/provenance), `discovery_signatures` (ancestor/leaf union), `queue_job_id` (FKsiz receipt), `dispatch_token` (stale delivery fence), `available_at` (retry), `lease_expires_at` (crash recovery). Queue retryda jobs.id o'zgarishi mumkin; token ayni dispatchni bog'laydi. Raw Viewstate yoki asl HTML/PDF ledgerga yozilmaydi.

Workflow: discovered → queued → processing → imported / unchanged / review / failed / skipped_alias; processing → retry_wait → processing. Explicit resume failed/stale itemni queuedga qaytarishi mumkin; terminal quality review avtomatik retry emas. Status/attempt/terminal timestamp/document CHECKlari va deferred completion triggerlari bor.

Review normal job natijasi, technical failure Laravel retry/exhausted failure. `failed_count` technical-only; review ledgerdan sanaladi. P2 item/outcome ma'lumoti migrationda ko'chirilib, old `failed_count` provenance'i `legacy_failed_count`da qoldi; eski failed_jobs saqlandi.

### Indekslar

- UNIQUE `document_sources.slug`
- UNIQUE `documents(source_id, external_id)`
- INDEX `documents(source_id, status, last_checked_at)`
- UNIQUE `document_identifiers(source_id, external_id)`; INDEX `document_id`
- UNIQUE `document_contents(document_id, language)`
- INDEX `scrape_runs(source_id, status)`
- Item UNIQUE `(scrape_run_id, source_external_id)`, `(scrape_run_id,status)`, `(scrape_run_id,document_id)` va processing lease uchun partial index.
- JSONB GIN, full-text va pgvector indekslari hozir yo'q. Katta ancestor-union query uchun GIN/query profiling keyingi alohida gate; 30 item testi scale isboti emas.

## 6. Kod arxitekturasi

```text
app/
  Models/
    Document.php
    DocumentIdentifier.php
    DocumentContent.php
    DocumentSource.php
    ScrapeRun.php
  Scraping/
    Contracts/
      DocumentScraper.php
    LexUz/
      LexUzScraper.php
      LexUzParser.php
      PdfTextExtractor.php
  Services/
    Documents/
      DocumentImporter.php
      DocumentValidator.php
  Jobs/
    DiscoverDocumentsJob.php
    ImportDocumentJob.php
  Console/
    Commands/
      SyncDocuments.php
config/
  scraping.php
routes/
  console.php
```

- **Contract**: manbani identifikatsiya qilish, hujjatlarni topish, bitta hujjatni yuklash / normal ko'rinishga keltirish; faqat kerakli metodlar.
- **Scraper**: Lex.uz'ga xos discovery va HTTP fetch; DB yozuvi yo'q.
- **Parser**: HTML/PDF ni formatga qarab ajratadi, barcha mavjud til variantlarini bildiradi, metadata + Markdown qaytaradi; DB yozuvi yo'q.
- **PdfTextExtractor**: Poppler text layer, kerak bo'lsa Tesseract OCR; oddiy yordamchi servis, ortiqcha class hierarchy yo'q.
- **Validator**: majburiy field, matn to'liqligi, noto'g'ri sahifa, keskin qisqarish, tillar va status.
- **Importer**: source identifier mapping orqali idempotent saqlash; har til uchun hash va mustaqil validatsiya; source row lock + PostgreSQL transaction. Mapping ikki mavjud documentga urilsa fail-closed; avtomatik merge yo'q. Validationdan o'tgan tillar saqlanadi, bir til xatosi boshqalarini bekor qilmaydi. Infrastructure xatosida transaction to'liq rollback bo'ladi.
- **Jobs**: discovery (interval/page checkpoint, queue dispatch), bitta external_id uchun import (mavjud tillari bilan), retry va monitoring.
- **Command/Scheduler**: `full`, `daily`, `recheck`, lock va konfiguratsiya.

`DocumentScraper` faqat portativ kutiladigan chiqish formatini belgilaydi; Lex.uz'dagi HTML selectorlar umumiy import servicelariga o'tmasligi kerak. Hozir repository pattern, event bus, microservices, o'nlab DTO yoki API controllerlar qo'shilmaydi.

## 7. Discover → Fetch → Parse → Validate → Save

1. **Discovery**: rasmiy qidiruv bo'limlari bo'yicha `active` + `pending` hujjat IDlarini topish; tur/sana filtrlarini tekshirish; natija katta bo'lsa sana intervalini kichraytirish; pagination va count reconciliation.
2. **Queue**: unique source+external_id nomzod uchun `ImportDocumentJob`; qayta yuborilish idempotent. Discovery checkpoint faqat navbatga qo'yish ishonchli tugagach advance qilinadi.
3. **Fetch**: hujjat sahifasi, til manzillari, haqiqiy fayl `Content-Type` va bayt signaturasini tekshirish; PDFga o'xshash URL har doim PDF baytlari degani emas.
4. **Parse**: tegishli HTML/CSS selectorlar, PDF text layer, fallback OCR; barcha mavjud tillar.
5. **Normalize**: sarlavha/bob/modda/band/ilova/jadval bog'lanishlarini iloji boricha Markdown sifatida; navigatsiya, reklama, tarixiy tahrir UI elementlari olib tashlanadi.
6. **Validate**: hujjat ID, title, ishonchli status, asosiy matn, mavjud tillar, PDF sahifalari; mantiqsiz juda qisqa natijalar `needs_review`/failed tarzida ko'riladi, eski contentni bosmaydi.
7. **Save**: identifier UNIQUE + DB transaction; har tilni mustaqil validate/save, normal hash, update/unchanged. Invalid/partial yangi matn eski yaxshisini bosmaydi; metadata-only tarjima `document_contents`ga yozilmaydi. Zarur full-text tillardan biri olinmasa import incomplete; `last_checked_at` faqat hammasi muvaffaqiyatli bo'lganda yangilanadi.
8. **Progress**: `scrape_runs` hisoblagich, checkpoint, failed_jobs va terminal report.

### Markdown siyosati

- Hujjatning asosiy sarlavhasi `#`, bo'lim/bob `##`, modda `###` (rasmiy raqamlanish saqlanadi).
- Raqamli bandlar, sub-bandlar, footnote va matnli ilovalar mavjud bo'lsa saqlanadi.
- Jadval strukturasini saqlash mumkin bo'lsa Markdown jadval; juda murakkab jadvalni tartib saqlagan ko'rinishda chiqarish va quality check.
- Ko'rinmay qolgan chizma/rasm, skanerlangan sahifa yoki jadvallar bo'yicha ma'lumot **jim o'tkazib yuborilmaydi**: incomplete sifatida ko'rinadi.
- HTML/PDF original fayl doimiy storagega yoki DBga yozilmaydi; vaqtinchalik fayllar `try/finally` bilan o'chiriladi.

## 8. Hybrid Sync

| Jarayon | Mexanizm |
|---|---|
| Initial Full | Har bir qidiruv tur/vaqt segmenti, active + pending, current matnlar |
| Daily | Har kuni yangi hujjatlar, oxirgi 7 kun overlap, topilgan o'zgarish signallari |
| Status | Pending expiry sanasida statusni **manbadan** yana tekshirish; inactive tasdiqlansa statusni saqlash |
| Rolling Recheck | Hamma active hujjatlarni 90 kun davomida navbat bilan tekshirish; `last_checked_at` asc |
| Inactive | Oldingi saqlangan matn qoladi; odatiy rolling recheckga kiritilmaydi |
| Exception | HTTP 429/503 da backoff, `Retry-After`; 403/bloklanganda agressiv retry qilmaslik |

**Cheklov:** Lex.uz'dagi 100% tahrir/status o'zgarishlarini kunning o'zida tutadigan to'liq delta-feed hali isbotlanmagan. Rolling recheck — shu noma'lumlikni kamaytiruvchi fallback, real-time kafolat emas.

## 9. Konfiguratsiya (boshlang'ich, server cheklovi emas)

```dotenv
DB_CONNECTION=pgsql
QUEUE_CONNECTION=database
CACHE_STORE=database
SCRAPING_REQUEST_DELAY_MS=2000
SCRAPING_HTTP_TIMEOUT=30
SCRAPING_PDF_TIMEOUT=90
SCRAPING_MAX_RETRIES=3
SCRAPING_JOB_TIMEOUT=900
SCRAPING_RECHECK_DAYS=90
SCRAPING_DAILY_TIME=02:00
SCRAPING_TIMEZONE=Asia/Tashkent
```

- 1 ta scraping worker.
- `queue.database.retry_after` ish timeoutidan **uzunroq** bo'lishi kerak (`900` uchun `960` namuna; real sozlashda tekshirish).
- Rate limit umumiy HTTP so'rovlari (discovery, HTML, til, PDF) uchun tatbiq etiladi; faqat job oralig'i emas.
- Queue worker local `queue:work`, scheduler local `schedule:work`, serverda Supervisor + cron.
- Shared locks: bitta manba uchun bir xil discovery overlap qilmasligi; importlar idempotent bo'lishi. Full paytida daily yursa, dublikat dispatchni to'sish va monitoring aniqligi sinovdan o'tadi.

P2da ishlaydigan bounded CLI interfeys (1..30, explicit live configuration bilan):

```bash
php artisan documents:sync lex --mode=full --limit=30
php artisan documents:sync lex --mode=daily --limit=30
php artisan documents:sync lex --mode=recheck --limit=30
php artisan documents:sync lex --report=1
php artisan documents:sync lex --resume=1
php artisan queue:work database --queue=scraping --tries=3 --timeout=900
php artisan schedule:work
```

`--limit` sinov sessiyasi full coverage `completed` deb noto'g'ri qayd etilmasligi kerak.

## 10. Strict Completion — aniq qoidalar

- **Running**: discovery yoki import joblari davom etmoqda.
- **Completed**: kerakli source/search segmentlari to'liq aylanilgan, barcha dispatche qilingan ishlarning final natijasi ishonchli hisoblangan, mavjud tillar to'liq validatsiyadan o'tgan, unresolved error yo'q.
- **Completed_with_errors**: discovery yakunlangan va joblar tugagan, lekin unresolved failures, til yetishmovchiligi yoki quality review mavjud.
- **Failed**: discovery yoki asosiy orchestration to'xtagan; to'liq qamrov aniqlanmagan.

**Coverage** alohida o'lchanadi: qidiruv segmentlari soni, manba qaytargan total bilan topilgan unique ID farqi, pagination oxirigacha yurilganmi, barcha qidiruv bo'limlari qamralganmi. Hech qanday yakunlash statusi rasmiy manbadagi 100% hujjat topilganiga mutlaq dalil bo'la olmaydi — bu chegarani reportda ko'rsatish kerak.

## 11. Test va qabul qilish mezonlari

- Turli toifadagi **30 ta** hujjatni ko'z bilan solishtirish (HTML, PDF, OCR, turli tillar, kodeks, qaror, ilova, jadval).
- **1000 ta** hujjat stress/integration import: job timeout, checkpoint, restart, retry, no duplicates.
- Bir hujjatni ikki marotaba import: birinchi create, keyingisi unchanged, unique constraint buzilmaydi.
- Matn o'zgarsa: content/hash yangilanadi, eski tahrir jadvali yaratilmaydi.
- Matn PDF/OCRdan noto'g'ri ajralsa: avvalgi yaxshi matn ustiga yozilmaydi.
- Status `pending → active`, `active → inactive` (original content saqlanadi).
- Full session interruption → resume; run bilan ishlar aniq hisoblanadi, `completed` xatolarga qaramay qo'yilmaydi.
- Til manbada yo'q bo'lsa skip; bor bo'lib parse bo'lmasa failed/incomplete.
- Temp PDF/OCR fayllari ish tugagach tozalanadi.
- Markdown sarlavha, modda, band va ilova strukturasini saqlash testlari.
- Hujjat tekshirilganda `last_checked_at` faqat muvaffaqiyatli natija uchun yangilanadi.

## 12. Kodlashdan oldingi majburiy Lex.uz texnik tekshiruv (Spike)

P0/P0.1 bajarilgan: dalillar `P0_REPORT.md` va `P0_1_REPORT.md`da. Quyidagi savollarning global coverage, PDF/OCR quality va access kabi ochiq qismlari full importgacha yopilishi kerak; P1da qayta live tekshiruv bajarilmaydi:

1. **Discovery:** `official`, `unique` va boshqa qidiruv bo'limlari barcha tanlangan hujjat turlarini qamrab oladimi? Pagination/kursor qaysi HTTP endpoint orqali? Sana/status filtrlarining haqiqiy request parametrlari va total count ishlashi.
2. **Revisions/status:** hujjat sahifasida joriy tahrir va statusni mashina orqali ishonchli aniqlash; istisno kuchga kirish sanalari; birlamchi status va o'zgarish feed/RSS qamrovi.
3. **PDF:** `https://lex.uz/uz/docs/8557098` va `https://lex.uz/pdfs/8557098` misollarida sahifa HTML qaytayotgani ko'rinadi; real PDF baytlari qayerdan olinishi, Content-Type/signature va avtorizatsiya/cookie kerakmi aniqlash. PDF text layer yoki scanligini alohida sinash.
4. **Multilingual:** til variantlari mavjudligini qanday aniqlash va original matnning sifatini test qilish.
5. **Access:** Lex.uz'dan foydalanish shartlari, public `robots.txt`/avtomatik yuklama talablari, API yoki rasmiy yuklab olish usuli mavjudligi va ehtimoliy ruxsat masalalari; blokni aylanib o'tmaslik.
6. **Spike natijasi:** real request/response namunalari, selectorlar, cheklovlar va acceptance test fixturelari. Har qanday yechilmagan yuqori xavfli masala full importni boshlashdan oldin hal etiladi.

### Ma'lum tekshirish manbalari

- Lex.uz [qidiruv sahifasi](https://lex.uz/uz/search/official?lang=4&pub_date=month) — qabul qilingan sana, status, til, hujjat turi bo'yicha UI filtrlar.
- Lex.uz [8557098-hujjat](https://lex.uz/uz/docs/8557098) — kelajakda kuchga kiradigan hujjat, PDF-viewerga o'xshash holat.
- Lex.uz [axborotni taqdim etish shartlari](https://lex.uz/uz/axborot) — shaxsiy foydalanish, daromad maqsadida foydalanish va normativ-huquqiy hujjatlar istisnolari. Barcha turdagi hujjatlarni ommaviy yig'ish huquqini avtomatik tasdiqlamaydi.
- Laravel [Queues](https://laravel.com/framework/docs/13.x/queues) va [Scheduling](https://laravel.com/framework/docs/13.x/scheduling).

## 13. Kelajakdagi RAG integratsiyasi

- `documents.id` / `document_contents.id` barqaror identifikatorlar.
- Markdown + language + content_hash + source_url RAG uchun canonical source hisoblanadi.
- Keyinchalik `document_chunks` va zarur bo'lsa `chunk_embeddings` migrations bilan **alohida** qo'shiladi.
- Chunklar bob/modda/band yoki ma'no chegaralarida, hajm limiti bilan hosil qilinadi. Eski hash chunklari qayta indekslanadi.
- V1 da embedding modeli, vektor dimensiyasi, chunk uzunligi yoki pgvector indeksi oldindan qattiq belgilanmaydi.

## 14. Ishni boshqarish

- Ish bosqichlari `LEX_SCRAPING_TASKS.md`da belgilangan.
- Har bosqichda: implementatsiya, test, yakun xulosasi, task checkbox yangilanishi.
- Codex navbatdagi sessiyada fayllardan kontekstni o'qib, bajarilgan tasklarni tekshirib davom etadi.
- Keraksiz yangi biznes jadvali, katta abstraksiya yoki v1 doirasidan tashqariga chiqish bo'lsa, avval sabab va alternativani qayd qilish; avtomatik qo'shmaslik.
- Ushbu hujjat talablar/spec. P1 foundation `P1_REPORT.md`da; P2 bounded parser/discovery/queue dalillari `P2_REPORT.md`da. Global/full-scale acceptance hali bajarilgan hisoblanmaydi.

## 15. P1 uchun foydalanuvchi tasdiqlagan A/B/C yangilanishlari

2026-10-28 P1 topshirig'i oldingi P0/P0.1 takliflaridagi tegishli qismlarni almashtiradi:

- **A:** 5 biznes jadval; identifier mapping va UNIQUE `(source_id, external_id)`. Universal `documents.id`, o'zgarmas canonical anchor va reciprocal links + rekvizitlar bilan identity.
- **B:** har til mustaqil validate/save. Bir til xatosi boshqa valid tillarni bekor qilmaydi. Barcha talab qilingan full-text tillar olinmaguncha Strict Completion yo'q. Invalid yangi matn eski yaxshisini bosmaydi; metadata-only tarjima matn emas.
- **C:** rasmiy source metadata `effective_at` ustuvor. Source active + yetib kelgan metadata sana → active; future → pending; source revoked → inactive. `-8553692`: metadata `2026-10-23`, PDF `2027-01-01` → bazada `2026-10-23`, JSONB warning. Faqat body/PDF sana farqi statusni bloklamaydi; content quality guardlari saqlanadi. Metadata o'zi yo'q/ichki zid bo'lsa validation review; review biznes enum emas.

P0/P0.1 hisobotlari tarixiy dalil sifatida o'zgartirilmadi. `P0_1_PLAN_PROPOSALS.md`dagi discovery/checkpoint strategiyalari, global OCR thresholdlari va qolgan takliflar avtomatik tasdiqlangani yo'q. P1dagi konservativ 30% qisqarish guardi optimal/global production threshold tasdig'i emas; yakuniy quality siyosati alohida kelishiladi.

P1 foydalanuvchi topshirig'i avval P2 deb nomlangan schema/model hamda P4 importerning foundation qismini shu bosqichga aniq kiritdi. P1 yakunida P2 tasdig'i kutilgan edi; keyingi P2 topshirig'i quyidagi bounded discovery/parser/queue ishlariga aniq ruxsat berdi. P2dan keyingi bosqichlar va full import alohida tasdiq bilan.

## 16. P2 tasdiqlangan implementation scope va doimiy database nomlari

- Development doim `mening_fikrim_active`, test doim `mening_fikrim_active_test`. Eski P1 bazalari o'chirilmaydi. Future schema o'zgarishi faqat migrations; developmentda migrate:fresh taqiqlanadi. Test actual connection name tekshiruvi migrationsdan oldin bajariladi.
- P2 5 biznes jadvalini kengaytirmaydi. Laravel HTTP / DomCrawler / Process / Poppler / Tesseract, parser → validator → universal importer va Database Queue integratsiyasi mavjud P1 ustida quriladi.
- `nat/loc/int/tech/court/oliy`: til filtri yo'q, ASP.NET current hidden fields + source postback target. `official` supplemental, `unique` primary emas. P2 real tekshiruv 30 tagacha hujjat bilan chegaralangan; global coverage da'vosi yo'q.
- HTTP global pacer: barcha request va retrylar orasida kamida 2 s; database cache single-flight lock. 403 stop/cooldown, 429 Retry-After seconds/HTTP-date va bounded 5xx/connection retry. Cookie/Viewstate faqat transient, diagnostikada raw qiymatlar yo'q.
- P2dagi source-record item/outcome checkpoint ledgeri hard cap 30 bilan bounded. Queue row insert va checkpoint commit ayni PostgreSQL transactionda; import DB outcome bilan atomik. Raw Viewstate saqlanmaydi. Replay source total/prefix hashni tekshiradi, invalid state'da fresh GET, duplicate dispatch yo'q.
- Full import execution path'i P2da yo'q: explicit 1..30 limit va live flag kerak. Bounded run `coverage_verified=false`, global `completed` emas. Katta corpus uchun durable ledger/orchestration va 1000 test keyingi alohida tasdiq bilan loyihalanadi; checkpointga cheksiz ID/error ro'yxati to'planmaydi.
- HTML numeric superscript Unicode, normative headings va table text saqlanadi. Complex spans, rasm va unsupported math review. PDF page/geometry accounting va page-level OCR fallback; confidence 90/70/5% faqat dastlabki review signali. Document-level OCR certification hali yo'q. 30% qisqarish review guardi saqlanadi.
- Daily/recheck bounded commandlar va disabled scheduler configuration tayyorlanadi; real daily avtomatik faollashtirilmaydi. P3 yoki full import foydalanuvchining yangi aniq tasdig'isiz boshlanmaydi.

Bu bo'lim faqat P2 topshirig'ida aniq berilgan qarorlarni aks ettiradi. Qolgan P0.1 takliflari va yakuniy OCR/table acceptance thresholdlari blanket approval sifatida olinmaydi.

### P2 yakuniy dalili va ochiq takliflar

Implementation bajarildi: tarixiy segmentda 72/72 record, 4 page, fresh replayda 20 duplicate skip; olti rootning birinchi page'i. 30 sample 22 HTML/8 PDF, 36 unique OCR page; 17 validator pass/13 review. PG initial import 15 active document/45 identifier/37 content; repeat 0 create/update, 15 unchanged. Real valid pending full-text import quality review sabab tasdiqlanmadi. Global coverage, haqiqiy expiry/real server limitlari tasdiqlanmagan. Uch bounded running hech biri global `completed` emas.

P2 yakunidagi **tasdiqlanmagan takliflar**: complex table/image/math acceptance, PDF language/layout proof, OCR document-level certification, adaptive partition/durable masshtabli ledger, bulk access va alohida 1000 gate. P2 paytida P3 tasdig'i kutilgan edi. Keyingi P3 topshirig'i quyidagi 17-bo'lim scope'ini aniq tasdiqladi; yakuniy acceptance/global/full/1000 ruxsati bundan kelib chiqmaydi. Tafsilotlar tarixiy `P2_REPORT.md` F/G va yangi `P3_REPORT.md`da.

## 17. P3 tasdiqlangan scope va implementatsiya

Foydalanuvchining P3 topshirig'i oldingi besh-jadval cheklovini oltinchi `scrape_run_items` uchun aniq kengaytirdi; boshqa yangi biznes jadvalga ruxsat yo'q. P3 quality review/technical failure ajratilishi, durable tracking, PDF/OCR acceptance levels, adaptive checkpoint va offline benchmarkni aniq tasdiqladi. Qolgan tasdiqlanmagan P0.1 takliflari avtomatik qabul qilinmaydi.

- Universal identity/identifier mapping, metadata precedence va independent language admission saqlandi. Importer/validator provider-neutral. Quality review source recordlarini technical failed_jobsga aylantirmaydi.
- Per-item progress ledgerda; checkpointda joriy segment/prefix/total, DFS frontier va partition parentlari. Sana inclusive ikki bo'lakka ajraladi, ancestor source-ID union DB orqali yarashadi. Single-day cap/source drift coverage gap, skip emas. Global corpus, undated records va katta date registry isbotlanmagan.
- Shared HTTP pacer saqlandi; real interval >=2000 ms, live worker=1. `SCRAPING_WORKERS` advisory. Ikki worker/1000 ms faqat offline fake transport; default live tezlashtirilmagan. Multi-host clock/fairness kafolati berilmadi.
- HTML empty heading, recursive cell/coordinate/span va image/math evidence yaxshilandi. Complex semantics tasdiqlanmasa review. PDF bytes/page/text/order/structure/language/OCR alohida levels; optional Process/pdfplumber diagnostikasi universal admission emas.
- OCR confidence, physical script, metadata critical tokens, manual excerpt CER/WER, suspicious region coordinates, line-height hints va source/Markdown/TSV provenance bor. Missing/merged lines va document-level certification avtomatik isbotlanmagan; threshold pasaytirilmadi.
- Strict Completion run+ledger+counters+framework queue bilan yarashadi; bounded scope coverage=false. Enumga yangi global status qo'shilmadi. Review/required-language failure yoki pending retry bilan completed yo'q.
- Real pending `8548643` guruhi metadata bo'yicha 2027-01-01 effective date, 3 valid til bilan import qilindi. P2dagi `-8553692` uchun 2026-10-23 tarixiy fixture saqlandi; P3 source 2027-01-01 va HTML bo'lib kelganda shu yangi metadata ustuvor. IDga sana hardcode yo'q.

P4 scope, 1000 integration va full import keyingi alohida aniq tasdiq bilan. Manual certification workflow hisobotda **taklif**; auto-promote/frontend, full/1000 execution yoki real schedule faollashtirilgani yo'q.

P3 yakuniy dalili: 110test/443assertion;289live×200,min2.000021697s;16selectedgroup/19sourceID,3accepted/13review; real pending3til; dev16document/48identifier/40content;112ledgeritem,0queue,24oldfailedjobs. P4 **CONDITIONAL GO**, 1000/full **NO-GO**. Keyingi bosqich tasdig'i kutiladi; defaultlive/schedulerfalse va worker1/2000ms saqlangan.

## 18. P4 Corpus Collection MVP — foydalanuvchi tasdiqlagan o'zgarish

Quality validation endi collectionni bloklamaydi: ishonchli identity/status bilan usable matn verified yoki needs_review sifatida saqlanadi. Hamma extractable til saqlanadi; metadata-only va empty/unusable extraction fake contentga aylantirilmaydi. Source-confirmed inactive explicit sample ham legal inactive holati saqlangancha collect qilinadi; default active/pending discovery o'zgarmaydi. Metadata/identity ziddiyatida taxminiy association yoki status yaratilmaydi.

P4 bajarildi: [P4_REPORT](P4_REPORT.md),130test/581assertion,286realrequest,13oldreviewgroup persisted, final16canonical unchanged. Dev29/82/63,42verified+21needs_review text. Quality certification va global/1000/full gate saqlangan; collection muvaffaqiyati ular uchun approval emas.

Olti biznes jadval qoladi. `document_contents`: quality_status, quality_issues JSONB, quality_checked_at, extraction_metadata JSONB, pending_candidate nullable JSONB. Existing admission proof valid bo'lsa backfill verified; dalilsiz legacy row needs_review. Content ID/matn/hash saqlanadi, FK/UNIQUE o'zgarmaydi. Document-level quality derived, alohida duplicating column yo'q.

Verified contentni avtomatik downgrade qilish taqiqlangan. Yangi past sifatli usable Markdown/provenance bir latest pending_candidate'da saqlanadi; yangi trusted textni bosmaydi. Suspected shrink guard ham saqlanadi. Yangi verified source extraction normal safe update/promotion qiladi. Local bounded/dry-run recheck mavjud validator va extraction dalilini tekshiradi; source fetch/OCR/sertifikatsiya emas, matnni o'chirmaydi.

Run execution workflow/technical retry/Strict Completion saqlanadi. Imported text needs_review bo'lishi mumkin; persistence outcome va quality outcome alohida hisoblanadi. Reviewning saqlangani quality gate yopildi degani emas. To'liq portable contract va cheklovlar `IMPORT_CONTRACT.md`ning P4 bo'limida; dastlabki flow `p4/INITIAL_FLOW.md`da. No new OCR/microservice/storage/AI/frontend; live1worker/2000ms/cap30/budget400, scheduling disabled. Global/full/1000 execution tasdiqlanmagan.


## 19. P5 controlled Corpus Collection Pilot — aniq ruxsat etilgan scope

Up to200NEW canonical document,30→100→200 safety gate; source-ID/language/alias/repeat sanog'i target emas. Har run1..30va400request cap, bitta worker,>=2000ms va disabled scheduler saqlanadi. Existing database-cache infrastrukturasida4000requestlik cumulative campaign; discovery/card/PDF/retry/redirectlar bir budgetda. State forever, stopped state avtomatik reset qilinmaydi, yo'qolgan state bilan eski campaign receiptlari traffic boshlamaydi. Cacheclear taqiqlanadi; bu doimiy campaign arxivi emas, finite pilot coordination. Yangi table/migration/parser/identity rule yo'q.

403/429yoki2consecutive5xx/network failure stop latch, queue polling keyingi receiptni olmasdan chiqadi. Unfinished work/diagnostics saqlanadi; stopdan keyin avtomatik live resume yo'q. Stage advance exact oldingi canonical target va empty queue talab qiladi; controller har batch/stageda baseline content/hash/canonical/identifier/failed-job integrityni tekshiradi. P4 quality policy o'zgarmaydi.

Olti supported rootning bounded date partitionlari real count/unique-ID/end/replay bilan o'lchanadi; page-cap segment incomplete qoladi. P5 global corpus coverage,bulk permission,OCR certification,1000/full yoki deployment/scheduling tasdig'i emas. Joriy progress va commands:[p5/README](p5/README.md); final evidence:[P5_REPORT](P5_REPORT.md).


P5 yakuniy real evidence (2026-10-28–29):158NEW canonical/370newtext,final187document/509identifier/433content,344verified+89needs_review.143test/619assertion,P0offline16/P0.1offline37,PintPASS.1229/4000HTTP,all200,min2.000131773s,1worker;source-IDguard360da stop,HTTPbudget2771qoldi.34pagePDF/7pageTesseractOCR reviewtextsaqlandi;0newtechnicalfailedjob,original63hash/24failedjob/530historicalfileunchanged. Exact200ga42NEWyetishmaydi;bu limitni avtomatik oshirish ruxsati emas. 1000uchun NO-GO:avvalsource-IDyield/sampling qayta hisoblash,incompleteoliydatepartition va8metadata/identityreviewitemdiagnostikasi. Report/progress/commands P5_REPORT va p5/READMEda;stoppedbudget qayta ochilmaydi.

## 20. P5.1 — authorized pilot completion and availability

2026-10-29 foydalanuvchi P5.1ni alohida tasdiqladi. P5 stopped campaign o'zgartirilmadi. Yangi `p5-1-20261029`:42 NEW canonical,20→42 audit gate,180 selected source ID,1800 cumulative HTTP; har run1..30/400request,bitta worker,>=2000ms,scheduler disabled. Natija:42NEW+158P5=200;DB229/625/541,449verified+92needs_review;414HTTP,min2.000140510s,0technicalfailure,882.156s controller. Targetda terminal stop;P6/full/1000/schedule avtomatik boshlanmaydi.

Olti biznes jadval saqlandi; migration yo'q. `documents.metadata.language_availability` til bo'yicha available/not_available/unknown va source dalili/raw label/checked_at/bitta oldingi observationni saqlaydi. Short explicit translation notice→not_available; missing controls/global PDF navigation/HTTP200 o'zi tarjima mavjudligi yoki yo'qligi dalili emas. Content quality verified/needs_review, legal active/pending/inactive o'zgarmadi. Unknown coverage quality reviewga teng emas; model `language_coverage_status`ni alohida chiqaradi. Confirmed available missing/failed extraction document review/incomplete; eski verified text saqlanadi. Raw HTML/PDF arxivi qo'shilmadi.

Service-layer `BoundedDiscovery` database-cache checkpointda inclusive child interval/page prefix/total/source IDs/completeness saqlaydi. Page cap8,depth10,partition cap63 va campaign budget saqlandi. Oliy2020/statusY partition:1429/1429 source ID,15leaf/29reconcilednode,unresolved incomplete0. Single-day/depth/cap/count mismatch incomplete qoladi; completed checkpoint qayta fetch qilinmaydi. Bu global/undated/other-status coverage emas. Universal reciprocal identity va strict content validator yumshatilmadi;1-ҚҚ/1-КК va PDF nonreciprocal case'lar manual reviewda.

P5.1da118available/70unknown observation,0fresh confirmed-not_available/0available-failed.182legacy document fresh availability bilan qayta tekshirilmagan. Confirmed absence retained real P0 notice + offline regression bilan, available-failed synthetic fixture bilan sinalgan; live coverage gap yashirilmagan. PDF/OCR ikki P5 real sample qayta tekshirildi, unique diversity oshgani da'vo qilinmaydi.172tests/719assertions,Pint,old16+37offlinePASS. Tafsilotlar:[P5_1_REPORT](P5_1_REPORT.md),[machine evidence](p5_1/README.md). P6 CONDITIONAL GO faqat alohida authorization bilan.
