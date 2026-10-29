# P2 — Lex.uz Scraping Implementation & Integration

Sana: **2026-10-28**, vaqt zonasi **Asia/Tashkent**. P2 foydalanuvchining alohida topshirig'i asosida bajarildi. P1 Laravel loyihasi saqlandi; loyiha qayta yaratilmadi, legacy application kodi ko'chirilmadi.

**Natija:** 30 ta real hujjat guruhi tekshirildi: **17 valid, 13 review, yakuniy 0 texnik failure**. Dastlabki active/pending importda **15 document, 45 identifier, 37 Markdown content** saqlandi. Takroriy 27 talik run **15 unchanged, 0 created, 0 updated** qaytardi. Avtomatik suite **77 test / 306 assertion**, failure/error=0; avvalgi P1ning 46 testi ham o'tdi. **P3: CONDITIONAL GO. Full import: NO-GO.** P3 boshlanmadi va foydalanuvchining aniq tasdig'i kutiladi.

`valid` shu adapter/validatorning tekshirilgan mezonlariga tegishli. Bu barcha satrlarni qo'lda yuridik tekshirish yoki corpusning to'liq qamrovi sertifikati emas. `review` biznes statusi emas; active/pending/inactive'dan mustaqil sifat natijasi.

## A. Environment

| Qism | Tekshirilgan holat |
|---|---|
| Laravel / PHP | 13.39.0 / 8.5.11 |
| PostgreSQL | 17.11 |
| Composer / PHPUnit | 2.8.5 / 12.5.42 |
| Symfony | DomCrawler 8.1.5, CSS Selector 8.1.6, Process 8.1.9 |
| Poppler | 24.02.0; pdftotext, pdfinfo, pdftohtml, pdftoppm |
| Tesseract | 5.3.4; eng, rus, uzb, uzb_cyrl traineddata |
| Development DB | **mening_fikrim_active** |
| Test DB | **mening_fikrim_active_test** |
| Queue / cache | database / database; bir scraping worker |
| Scheduler / live default | false / false |

P2 boshida PostgreSQL bazalari o'qildi. `mening_fikrim_active`da public jadval va ma'lumot **yo'q edi**; P1ning 7 migrationi va 2 source seedi qo'llandi. Fixed `mening_fikrim_active_test` avval mavjud emas edi va bir marta yaratildi. Bu nomlar keyingi bosqichlarda ham o'zgarmaydi. Eski P1 va boshqa loyiha bazalari o'chirilmadi va ularga yozilmadi.

Development va reference `mening_fikrim_p1` schema kataloglari — columns, constraints va indexes — **aynan teng**. Yangi migration yoki biznes jadval qo'shilmadi: 5 biznes + 6 framework jadvali. Ikkala fixed bazada 7 migration `Ran`.

`.env`, `.env.testing`, examplelar, phpunit.xml, test bootstrap va child-process guardlari fixed nomlarga moslandi. Credentiallar faqat ignored local env fayllarda; examplelarda bo'sh. Testlar migrationdan avval konfiguratsiya va haqiqiy `current_database()`ni tekshiradi; DB_URL taqiqlangan, tashqi HTTP default suite'da bloklangan. Developmentda `migrate:fresh` bajarilmadi. Yakuniy auditda boshqa beshta mavjud bazaning jadval ro'yxati va row countlari avvalgidek: bu byte-level snapshot taqqoslash emas.

Dalillar: [oldingi DB holati](p2/database_before.json), [schema tengligi](p2/schema_comparison.json), [development migrations](p2/migrations_development.txt), [testing migrations](p2/migrations_testing.txt), [yakuniy DB auditi](p2/database_after.json), [platform tekshiruvi](p2/platform_requirements.txt).

Tesseract lokal P0 muhitidagi rootless binary/data/library yo'llari bilan ishlatildi. Ular portable deployment install sifatida ko'rsatilmaydi; boshqa muhitda tool va traineddata alohida o'rnatiladi. P0 tool fayllari saqlandi.

## B. Implementation

### Classlar va qatlamlar

| Qatlam | Yangi yoki kengaytirilgan classlar |
|---|---|
| Contract / source adapter | `DocumentScraper`, `LexUzScraper` |
| HTTP | `LexHttpClient`, `LexRequestException` |
| Parser | `LexUzParser`, `HtmlMarkdownParser`, `PdfTextExtractor` |
| Orchestration | `ScrapeRunManager`, `DiscoverDocumentsJob` |
| Queue / CLI | kengaytirilgan `ImportDocumentJob`, yangi `SyncDocuments` |
| Binding / konfiguratsiya | `AppServiceProvider`, `config/scraping.php`, logging channel, conditional console schedule |

Classlar `app/Scraping`, `app/Services/Scraping`, `app/Jobs`, `app/Console/Commands`da. P1 `DocumentImporter` va `DocumentValidator` universal qoldi: Lex selectorlari yoki qidiruv kodi ularga kiritilmadi. Adapter DBga document yozmaydi; parser chiqishi mavjud [IMPORT_CONTRACT](IMPORT_CONTRACT.md) orqali validate/import qilinadi. Repository pattern, event bus, yangi frontend/API yoki AI qatlamlari yaratilmadi.

### HTTP va real endpointlar

Laravel HTTP Client barcha discovery, card, HTML, PDF, redirect va retry so'rovlarini bir global pacerdan o'tkazadi. Minimal interval konfiguratsiya pastroq qo'yilsa ham **2 s**dan kamaymaydi. Shared database cache single-flight lock, per-run 400 request budget, HTML 30 s / PDF 90 s timeout, connect timeout ko'pi bilan 10 s, jami 3 urinish bor. 5xx/connection uchun bounded backoff; 403da stop va shared cooldown, 429da `Retry-After` seconds yoki HTTP-date hisoblanadi. 30 sdan uzun cooldown job release orqali kutiladi. Faqat HTTPS `lex.uz` va cheklangan redirectlar qabul qilinadi.

P0/P0.1da tasdiqlangan va P2da real ishlatilgan oqimlar:

- `GET https://lex.uz/uz/search/{nat|loc|int|tech|court|oliy}?status=Y` — **lang filter yo'q**.
- `GET/POST https://lex.uz/uz/search/nat?status=Y&from=01.01.2010&to=31.01.2010` — real sana segmenti va pagination.
- `/uz/docs/{source-id}` — mavjud source entry va undan olingan reciprocal til havolalari.
- `showIframeCard(...)` ichidan topilgan `/uz/actinfo/card1/{id}` — title, rekvizit, til, source status va sanalar.
- `PDFObject.embed(...)` ichidan topilgan PDF resource, jumladan [8557098 PDF](https://lex.uz/pdffile/8557098). Boshqa fayl URLlari IDga qarab taxmin qilinmaydi.

Request loglari URLning cheklangan query maydonlari, method, status, attempt, monotonic boshlanish, latency, decoded body bytes va SHA-256ni saqlaydi. Cookie, request form, Viewstate, token, credential yoki raw response loglanmaydi. [Requests](p2/requests.jsonl) — real P2 logining saqlangan nusxasi; [HTTP statistika](p2/http_stats.json).

### Discovery, pagination va checkpoint

Listing `a.lx_link`, real status classlari va source totaldan o'qiladi. `#Form1` hidden inputlari, jumladan `__VIEWSTATE`, `__VIEWSTATEGENERATOR` hamda joriy response'dagi `__doPostBack` target ishlatiladi. Har POST **eng so'nggi sahifa state'i** bilan yuboriladi. Unknown listing, positive total bilan bo'sh rowlar, cycled page, total/prefix drift yoki oxirgi sahifadagi count nomuvofiqligi jim skip qilinmaydi.

Invalid response holatida ko'pi bilan bir fresh GET replay bor. Durable checkpointda segment, committed page, total, page/prefix hash saqlanadi; raw Viewstate/cookie saqlanmaydi. Replay avvalgi prefixni tekshiradi, source ID dublikatlarini qayta dispatch qilmaydi. Interval/bo'limlar kesishsa ayni capped run'da ID dedup ishlaydi; turli source aliaslari esa universal importerda verified canonical groupga birlashadi.

Queue insert va checkpoint advance ayni PostgreSQL connection/transaction ichida: bu tor oqimda job `beforeCommit()` bilan shu transactionga yoziladi. Tashqi consumer rowni commitgacha ko'rmaydi. P1ning oddiy candidate joblari uchun after-commit xulqi saqlangan. Import va terminal outcome ham bitta outer transactionda qayd qilinadi.

P2da page budget 8, run limit 1..30. Algoritm kichik segmentni oxirigacha yuradi, lekin katta corpus uchun adaptive sana partition rejalashtiruvchisi va masshtabli durable ledger **hali yo'q**. Budget/limit bilan to'xtash muvaffaqiyatli global coverage emas.

### Identity, metadata va tillar

Source til linklari graph bo'yicha olinadi, reciprocal bog'lanishlar va type/form/issuer/date/number hamda mavjud optional rekvizitlar universal validatorda taqqoslanadi. Canonical mapping `document_identifiers`ga yoziladi. `abs(id)`, title yoki content hash merge dalili emas. Existing anchor o'zgarmaydi. PDF global UI til menyusi translation graph hisoblanmaydi. RU/EN metadata-only notice alohida aniqlanadi va `document_contents`ga matn sifatida yozilmaydi. Noma'lum linked language yoki ONDATE historical revision linki review/failure talab qiladi.

Status source metadata orqali: revoked → inactive; active + future effective_at → pending; active + yetib kelgan sana → active. `Amalda` lokal labeli faqat ayni IDning real listing `Y` dalili bilan qabul qilinadi. Unknown yoki ichki zid metadata reject qilinadi. Body/PDFdagi boshqa butun-hujjat sanasi warning; u source effective_atni almashtirmaydi.

Har til mustaqil validate/save qilinadi. Invalid til eski yaxshi matnni bosmaydi, valid boshqa til bekor qilinmaydi. Barcha required full-text tillar shu fetchda valid bo'lmaguncha `last_checked_at` yangilanmaydi. Bu yo'llar real PostgreSQL va fake HTTP bilan end-to-end tekshirildi; isolated testda haqiqiy source candidate bilan ham tekshirildi.

### HTML, PDF va OCR

HTML DomCrawler orqali `#divCont`dan olinadi. Normativ sarlavha/bob/modda/ilova classlari headingga, band/sub-band matni mavjud raqamlanishi bilan Markdownga o'tadi. Editorial/comment/old-edit UI node'lari chiqariladi. Numeric superscript Unicodega o'tadi. Oddiy table celllari Markdown jadvalga o'tadi; rowspan/colspan/nested table semantikasi, rasmlar va unsupported mathematical superscript review beradi. Tozalangan/transformed source tokenlari va Markdown tokenlarining tartibi tengligi tekshiriladi. Bu tozalanish qoidalarining o'zi xatosiz ekanini mustaqil isbotlamaydi.

PDF uchun `%PDF-`, Content-Type, file/page limit, Poppler page count, text page count va geometry page count tengligi tekshiriladi. Sahifa chegaralari Markdown commentlari bilan qoladi; plain text semantik Markdown deb qabul qilinmaydi. Hozir barcha PDFlar `pdf_structure_unverified` va `pdf_language_coverage_unconfirmed` sabab review.

Page-level image area va text body/vertical coverage partial layerni ham aniqlaydi. Kerakli sahifagina 2400 pxgacha render qilinadi; Tesseract `uzb_cyrl+uzb`, rus yoki eng modelini ishlatadi. TSVdan word confidence olinadi: mean<90 yoki conf<70 so'zlar ulushi>5% review signali. OCR doim document-level certification talab qiladi; confidence yuqoriligi uni valid qilmaydi. Metadata raqam/sana tokenlari matnda qidiriladi, script mismatch va source IDni OCRdan sertifikatlab bo'lmasligi alohida signal. Bandlar, barcha muhim raqamlar va sanalarning xatosizligini to'liq tekshirish hali yechilmagan.

Limitlar: 32 MiB response/PDF, 250 PDF page, 40 OCR page, 600 s total parse budget, 90 s process / 45 s OCR page timeout, 256 MiB temp budget, bir OCR thread. Subprocess Symfony Process array argumentlari bilan ishlaydi; timeout/failureda process to'xtatiladi va temp `finally`da tozalanadi. SIGKILLda `finally` kafolatlanmaydi; bir soatdan eski matching orphan temp kataloglar keyingi extractionda tozalanadi. Response hajmi HTTP body olingandan keyin tekshiriladi; streaming download memory cap hali yo'q. Bu limitlar OS darajasidagi hard memory sandbox emas.

### CLI va scheduler

```bash
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=full --limit=30
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=daily --limit=30
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=recheck --limit=30
php artisan documents:sync lex --report=1
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --resume=1
SCRAPING_LIVE_ENABLED=true php artisan queue:work database --queue=scraping --tries=3 --timeout=900 --stop-when-empty
```

`--sample=JSON` explicit tekshirilgan sample uchun; `--section=int` bounded sinovni bitta verified root bilan cheklaydi. Limitsiz, 0 yoki >30 limitli execution yo'q; `--mode=full` hozir capped initial strategiya nomi. Source `is_active` guard ham bor. Daily 7 kun overlap; recheck due active/null last-check va effective sanasi yetgan pendingni tanlaydi, inactiveni odatiy recheckdan chiqaradi. 02:00 Asia/Tashkent schedule `withoutOverlapping/onOneServer` bilan tayyor, **o'chirilgan**. Yakuniy `schedule:list`: 0 event. Delayed retry mavjud bo'lsa `--stop-when-empty`dan keyin worker retry vaqti yetgach qayta ishga tushiriladi. [README](../README.md)da operatsion yo'riqnoma bor.

## C. Real Test

### Discovery natijalari

| Root, til filtrisiz Y | Source total | P2 real yurilgan |
|---|---:|---|
| nat | 55 971 | birinchi 20 row |
| loc | 22 307 | birinchi 20 row |
| int | 4 997 | birinchi 20 row |
| tech | 3 221 | birinchi 20 row |
| court | 1 613 | birinchi 20 row |
| oliy | 30 900 | birinchi 20 row |

Bular raw source record countlari; yig'indisi canonical hujjatlar soni emas. Bo'limlar overlap qilishi va til aliaslari bo'lishi mumkin.

Tarixiy `nat`, 01.01.2010–31.01.2010 segmenti P2 paytida **72 total / 72 unique / 4 page** bilan yakunlandi. Birinchi 20 ID commitdan keyin fresh session replay qilindi: **20 duplicate skip**, union=72. P0/P0.1dagi 80 bilan farq source natijasi vaqt oralig'ida o'zgarishi mumkinligini ko'rsatadi; eski hisobot qayta yozilmadi. Bu real token expiry testi emas — P2da expiry kuzatilmadi. Invalid/expired/cycling response faultlari offline berildi. Discovery sinovi 11 request, **20.445 s**. [Dalil](p2/discovery_live.json).

Alohida **run 3**: `--mode=full --limit=1 --section=int`, explicit sample ishlatilmadi. Listing → Discovery job → Import job → real PG oqimi ishladi. Topilgan `8507863` avvalgi `8506802` guruhining verified RU aliasi edi: **1 unchanged**, yangi hujjat yo'q, 17 s, 9 request. Shunday qilib 30 ta distinct sample guruhi chegarasi oshmadi. [Run 3](p2/run_3.json), [worker](p2/worker_discovery_queue.txt).

### Sample import va qayta import

P0 manifestidagi o'sha 30 guruh tanlandi, lekin **P2 matnlari real qayta fetch/parse qilindi**. 27 active/pending dastlabki sample, 3 inactive alohida tekshirildi. Kodekslar, qonun, qaror, buyruq, xalqaro deklaratsiya/memorandum, mahalliy qaror, sud amaliyoti va texnik hujjat bor. Masalan Mehnat kodeksi `-6257288`, Suv kodeksi `-7655343`, ilova/jadval `8554309`, xalqaro `8506802`, scan `-8553692`.

| Ko'rsatkich | Birinchi run: 27 | Takroriy run: ayni 27 | Alohida inactive: 3 |
|---|---:|---:|---:|
| Processed | 27 | 27 | 3 fetch/validation |
| Created | 15 | 0 | initial importga kiritilmadi |
| Updated | 0 | 0 | controlled prior state'da 1 update testi |
| Unchanged | 0 | 15 | tatbiq etilmaydi |
| Valid / review | 15 / 12 | 15 / 12 | 2 / 1 |
| Yakuniy texnik failed | 0 | 0 | 0 |
| HTML / text PDF / OCR PDF | 19 / 6 / 2 | 19 / 6 / 2 | 3 / 0 / 0 |
| Source active / pending / inactive | 24 / 3 / 0 | 24 / 3 / 0 | 0 / 0 / 3 |

**30 distinct guruh:** 22 HTML, 6 text PDF, 2 scan PDF; **24 active, 3 pending, 3 inactive**. PDF va OCR umumiy format sifatida 8 PDF, ularning 2 tasida OCR. 27 sample'da **55 full-text variant, 13 metadata-only variant** tekshirildi; inactive uchlikda yana 7 full-text variant: jami **62 full-text**. Metadata-only variantlar content row emas.

Developmentga yozilgan 37 content: **uz-Latn 15, uz-Cyrl 15, ru 4, en 3**. Har birining hash'i bazadagi contentdan qayta hisoblanib teng chiqdi. Development source statusi valid bo'lgan **15 active** documentga ega; **0 pending, 0 inactive**. Uch pending (`8557098`, `8554315`, `8548754`) source status testidan o'tdi, ammo content quality review sabab yangi document sifatida qabul qilinmadi. Pendingni PostgreSQLga saqlash foundation/offline integration testlarda tekshirildi; ushbu real campaign'da valid pending matn importi **tasdiqlanmadi**.

PDF unique pass: **101/101 page** accounting; OCR **17+19=36 page**. Ikkinchi run ayni PDF/OCRni qayta ishladi: bu yangi 36 distinct sahifa emas. Isolated safe-update candidate uchun `-8553692` yana olindi; qo'shimcha bitta sahifadan visual/gold excerpt tekshirildi. Ular unique 101/36 hisobiga qo'shilmadi.

### Vaqt va requestlar

- Run 1: DBdagi recorded duration **789 s**. Worker loglaridagi birinchi/oxirgi ish oynasi 15:35:34–15:46:40, taxminan **666 s**, restart va pausalar bilan. Dastlab report reconciliation terminal `finished_at`ni qayta yozgani uchun 789 s reportgacha bo'lgan kechikishni ham qamradi. Bu timing bug tuzatildi va terminal timestamp o'zgarmasligi regression testi qo'shildi. Run 1ning tarixiy metrici sun'iy tuzatilmadi; sof throughput sifatida ishlatilmaydi.
- Run 2: **470 s (7 min 50 s)**, DB timestamps bilan; barcha 27 qayta ishlangan. Run 3: 17 s. Inactive quality retest: **34.844 s**.
- Jami **371 real HTTP request: 368 GET + 3 POST; 371×200**. Contextlar: discovery 11, run1 154, run2 156, inactive-fetch 21, run3 9, inactive-quality 20. Oxirgi 20 ichida qo'shilgan language guardning bir to'xtagan urinishidagi 2 request ham bor; yashirilmadi.
- Eng kichik o'lchangan request-start interval: **2.00003091 s**. Decoded response body jami **101 149 942 byte**; wire traffic emas. HTTP latency yig'indisi 80.446 s; bu OCR, pacing, worker/restart yoki operator vaqtini o'z ichiga olmaydi.
- Birinchi/oxirgi live log 15:34:23–16:41:26: taxminan **67 min 4 s** wall-clock oyna. Kod tuzatish, QA va pausalar bor; uzluksiz import davomiyligi deb olinmaydi. Umumiy development/mehnat vaqti o'lchanmadi.

Real 403, 429, 5xx, timeout yoki bloklash kuzatilmadi. Shu holatlarning retry/cooldown xulqi **fake HTTP + virtual clock** testlari bilan tasdiqlandi; real server limitini aniqladik degan da'vo yo'q. Stress yoki limitni buzib ko'rish bajarilmadi.

Dalillar: [campaign statistika](p2/campaign_stats.json), [30 natija](p2/sample_results.json), [run 1](p2/run_1.json), [run 2](p2/run_2.json), [inactive sifat](p2/inactive_live.json), [HTTP](p2/http_stats.json).

## D. Quality

**17 valid / 13 review / 0 unresolved technical failure** — barcha 30 uchun yangidan o'lchangan natija. P0: 11 pass / 19 review. P0.1: 17/13 kompozit natija bo'lib, eski 11 pass carry-forward qilingan edi. P2 shu 17/13 taqsimotni Laravel adapterida real qayta olish bilan takrorladi; bu qolgan 13 masala yechildi degani emas. `-8553692`ning ilgari noaniq statusi tasdiqlangan metadata precedence bilan active; content esa review.

### Muammoli hujjatlar — to'liq ro'yxat

| ID va real URL | Review sababi |
|---|---|
| [8548754](https://lex.uz/uz/docs/8548754) | Ikki o'zbek matnida rasmlar/chizmalar; `unextracted_images`. Source pending. |
| [8554309](https://lex.uz/uz/docs/8554309) | Murakkab rowspan/colspan jadval; cell matni olindi, semantikasi tasdiqlanmagan. |
| [-7655343](https://lex.uz/uz/docs/-7655343) | Suv kodeksi: 4 full-text tilidagi murakkab jadval semantikasi review. |
| [-8540314](https://lex.uz/uz/docs/-8540314) | Murakkab jadvallar, chizmalar va nonnumeric mathematical superscript. |
| [-8102878](https://lex.uz/uz/docs/-8102878) | Inactive patent talablari: murakkab spanlar va matnli superscript; initial importga kiritilmadi. |
| [8557098](https://lex.uz/uz/docs/8557098) | 9-page text PDF; table/layout strukturasi va barcha PDF tillari coverage'i tasdiqlanmagan. Source pending. |
| [8554315](https://lex.uz/uz/docs/8554315) | Text PDF strukturasi va til coverage'i tasdiqlanmagan. Source pending. |
| [8554432](https://lex.uz/uz/docs/8554432) | Text PDF strukturasi va til coverage'i tasdiqlanmagan. |
| [8554353](https://lex.uz/uz/docs/8554353) | Text PDF strukturasi va til coverage'i tasdiqlanmagan. |
| [8554326](https://lex.uz/uz/docs/8554326) | Text PDF strukturasi va til coverage'i tasdiqlanmagan. |
| [8554321](https://lex.uz/uz/docs/8554321) | Text PDF strukturasi va til coverage'i tasdiqlanmagan. |
| [-8553258](https://lex.uz/uz/docs/-8553258) | 17-page OCR: uncertified, confidence/critical tokens, card Latn–physical Cyrl farqi, PDF structure/language coverage. |
| [-8553692](https://lex.uz/uz/docs/-8553692) | 19-page OCR: uncertified, confidence/critical tokens, script mismatch, PDF structure/language coverage. Source 2026-10-23 va PDF 2027-01-01 farqi alohida warning. |

Review sabablarining har til bo'yicha to'liq mashina natijasi [sample_results](p2/sample_results.json), page/confidence ma'lumotlari [run 1](p2/run_1.json)da. `failed_jobs`da 12 review × 2 run = **24** row saqlandi. Bu 24 ta distinct xato hujjat yoki 24 ta texnik extraction failure degani emas. `scrape_runs.failed_count=12` quality reviewni ham qamraydi; terminal summary ularni `failed=0`, `review_required=12` qilib ajratadi.

### Matn sifati va cheklovlar

Mehnat kodeksining real Markdowni PostgreSQLdan o'qildi: `#` sarlavha, `##` qism/bo'lim/bob, `###` modda bor. Eng katta saqlangan variant **721 465 belgi**. Numeric superscript, normativ heading, oddiy table va real complex-table fixture regressionlari o'tdi. Matnli ilovalar va bandlar content tarkibida; ular uchun alohida DB entity yoki huquqiy section tree yaratilmagan. Stored Markdownda **3 ta bo'sh `##` heading** aniqlandi — source bo'sh header node'lari; ma'no yo'qolishi aniqlanmadi, lekin kosmetik cleanup hali ochiq va bu runlar hashlariga tegilmadi.

PDF `8557098`ning 9-sahifasi ko'z bilan ko'rildi: real jadvalni plain layout chiqarish semantik table sifatiga teng emas. Shu sabab u saqlashga qabul qilinmadi. PDFdagi o'qish tartibi, nested jadvallar va grafiklarning to'liqligi umumiy tarzda sertifikatlanmadi.

OCR: `-8553258` page mean confidence diapazoni **81.4993–95.4721**; 1 page mean<90, 2 pageda low-confidence ulushi>5%. `-8553692`: **91.5985–96.0514**, mean<90 page yo'q, lekin 1 pageda low-confidence ulushi>5%. 3 distinct page confidence sababli signal berdi; ikkala document baribir sertifikatsiz OCR sabab review. `-8553692` 1-sahifa 4-bandning yangi renderidan 42 normalized belgili qo'lda transkripsiya Tesseract natijasi bilan teng: CER=0. Bu **bitta kichik excerpt**, butun 19 page yoki boshqa hujjatlarning accuracy kafolati emas. [Spot-check dalili](p2/ocr_spotcheck.json).

Matn yo'qolishi/xato talqini mumkin bo'lgan joylar: source selector o'zgarishi; editorial cleanup noto'g'ri node'ni chiqarishi; rasm/chizma; merged/nested table cell munosabatlari; matematika; PDF multi-column/order; noto'g'ri text layer; OCR sanalar/raqamlar/band belgilarining almashishi; PDFda boshqa tilning graph orqali ko'rinmasligi. Known holatlar review bo'ladi; butun manba uchun noma'lum holatlarning yo'qligi isbotlanmagan. 30 hujjatni satrma-satr to'liq manual QA qilish bajarilmadi.

Developmentda topilib tuzatilgan nuqsonlar ham saqlandi: DomCrawler relative table-cell XPath, localized `O‘zbekcha (L)` label, queue test harness failed-job hook, terminal finished_at reset, unknown-language guardning PDF download buttonini noto'g'ri qamrashi. Oxirgi guard real to'xtagan urinish va regressiondan so'ng `/docs/` til linklariga toraytirildi; PDF download UI testi yana o'tdi. Yakuniy 77 testda failure/error yo'q; dastlabki muvaffaqiyatsiz development loglari `p2/`da qolgan.

## E. Database & Queue

### Yakuniy saqlangan holat

| Jadval | mening_fikrim_active | mening_fikrim_active_test |
|---|---:|---:|
| document_sources | 2 | 2 |
| documents | 15 | 2 controlled test state |
| document_identifiers | 45 | 4 |
| document_contents | 37 | 3 |
| scrape_runs | 3 | 0 |
| jobs | 0 | 0 |
| failed_jobs | 24 quality review | 0 |
| migrations | 7 | 7 |

Test bazasidagi oxirgi ikki document production ma'lumoti emas: biri real inactive candidate + sun'iy oldingi active state; ikkinchisi real scan candidate + controlled oldingi valid text. Suite boshqa test state'larini shu fixed test bazada yaratdi/tozaladi; development rows testlar tomonidan tozalanmadi. P1ning bo'sh bazalari va boshqa legacy jadvallar saqlandi. [DB audit](p2/database_audit_terminal.txt).

**Idempotency:** run2dan keyin document/identifier/content sonlari 15/45/37ligicha, barcha 37 content ID va hash o'zgarmagan. `0 created, 0 updated, 15 unchanged, 12 review`. Verified metadata-only aliaslar identifier sifatida bor, content sifatida yo'q. Source row lock, identifier UNIQUE va composite FK P1dan saqlangan. [Taqqoslash](p2/idempotency.json).

**Inactive update:** [8181977](https://lex.uz/uz/docs/8181977) real revoked metadata bilan isolated test DBdagi controlled active documentni inactive qildi, ikki real til hashini aynan saqladi. Bu o'tmishda real source active bo'lganining kuzatuvi emas. [Dalil](p2/inactive_update.json).

**Metadata/content mustaqilligi:** real `-8553692` candidate controlled oldingi pending documentni metadata bo'yicha active qildi, `effective_at=2026-10-23`; PDF `2027-01-01` JSONB warning bo'ldi. Yangi uncertified OCR eski Cyrl text hashini bosmadi, `last_checked_at=2026-10-20`ligicha, `complete=false`. [Dalil](p2/scan_safe_update.json).

**Retry/failed tracking:** haqiqiy Laravel DatabaseQueue Worker va PostgreSQL bilan fake HTTP 503→success hamda exhausted/partial review yo'llari tekshirildi. Real Lex javoblarida transient HTTP xato kuzatilmadi. Real run1da localized card label kamchiligi outer job retrylarini keltirib chiqardi; mapping tuzatilgach yakuniy texnik failure qolmadi. Standard `queue:work` failed-job provider quality reviewni logladi; run counters terminal outcome bo'yicha bir marta sanaladi.

**Restart/resume:** run1 o'rtasida haqiqiy `queue:restart` bajarildi, worker joriy ishni tugatib chiqdi; `--resume=1` existing pending joblarni ko'paytirmadi. Keyingi worker ayni runni tugatdi. Bu graceful restart testi; haqiqiy SIGKILL/power loss tajribasi emas. Crash boundary, queue/checkpoint rollback, replay drift va limit-commit holatlari offline PostgreSQL testlarda tekshirildi. [Resume](p2/resume_terminal.txt), [worker davomi](p2/worker_first_resumed.txt).

**Strict Completion:** barcha 3 live run `completed_with_errors`; `coverage_verified=false`. Run3da quality xato bo'lmasa ham bounded coverage sabab `completed` emas. `jobs_reconciled`, required tillar va quality gate'lari alohida qayd qilinadi. `jobs=0` yoki oldingi yaxshi matn mavjudligi yangi failed fetchni complete qilmaydi. Terminal report `finished_at`ni endi o'zgartirmaydi.

### Avtomatik tekshiruvlar

- **77 test / 306 assertion — hammasi o'tdi**, 15.043 s, tashqi HTTPsiz, real PostgreSQLda: avvalgi P1 46 testi + P2 31 testi. [Test output](p2/test_output.txt), [JUnit](p2/junit.xml), [P1 alohida regression](p2/p1_regression_output.txt).
- HTML/metadata/language/PDF-geometry fixturelari, common pacing, 429 seconds/date/defer, 403 cooldown, 5xx/connection timeout, URL/log guards; invalid PDF/process failure cleanup.
- Real DB queue, transaction rollback, independent languages/old text protection, checkpoint replay/dedup/drift, bounded CLI, recheck due selection, immutable finish timestamp. Fixture transport rekonstruksiyalari real HTTP sifatida sanalmadi.
- Pint passed; Composer strict validate va platform requirements passed; ikkala DB migration statusi `Ran`; scheduler 0 event. [Pint](p2/pint_final.txt), [Composer](p2/composer_validate.txt).
- P0/P0.1/P1dan **214 fayl hash'i o'zgarmagan**. [Integrity](p2/historical_integrity.json).
- QA private tempda 12 fayl / 6 547 821 byte o'chirildi, source/PNG/XML/candidate katalogi qolmadi. Parser working rootida `pdf-*` katalogi **0**. DBda raw HTML/PDF yo'q. [Cleanup](p2/cleanup.json).

## F. Remaining Issues

1. **13 quality review ochiq.** Complex table, rasm/math, PDF structure va til coverage, document-level OCR certificationni foydalanuvchi bilan kelishilgan qabul mezoniga yetkazish kerak. P2 bularni valid deb maskalamaydi. Real pending full-text import acceptance hali ochiq.
2. **Global discovery coverage tasdiqlanmagan.** Olti katta root faqat birinchi page, tarixiy bitta segment oxirigacha yurildi. `Y`dan tashqari noto'g'ri classified yangi source recordlar bor-yo'qligi hamda barcha tillar/root overlaplari uchun umumiy proof yo'q. `unique` primary emas, `official` supplemental yo'l sifatida mavjud; P2da uning live cross-checki takrorlanmadi.
3. **Haqiqiy token expiry kuzatilmadi.** Offline invalid-state replay bor. Uzoq session/large-page drift, crash/power loss va source cap uchun alohida nazoratli gate kerak.
4. **Bulk access va full request hajmi noma'lum.** P0/P0.1 public access dalillari saqlandi; mass automation shartlari, serverning haqiqiy rate limitlari yoki rasmiy bulk feed to'liq tasdiqlanmagan. 371×200 rate-limit yo'qligi degani emas.
5. **Masshtabli orchestration hali loyihalanmagan.** Capped JSONB item/outcome ledgerini cheksiz corpusga yoyish mumkin emas. Adaptive partition, durable per-item accountability va 1000 hujjat gate'i uchun alohida tasdiq kerak; yangi biznes jadval avtomatik qo'shilmaydi.
6. **Daily/recheck live uzoq muddat testi bajarilmadi.** Selector/offline selection/schedule configuration tayyor; actual cron faollashtirilmadi. Universal delta-feed va bir kunlik barcha tahrirlarni ushlash kafolati yo'q. Full/daily concurrency va supervisor/deployment keyingi ruxsatga tegishli.
7. **Resurs va quality cheklovlari.** Streaming response cap, OS hard memory limit, oversized/decompression stress, barcha tillar uchun tabiiy OCR corpus va full manual QA keyingi baholashga qoladi. Real OCR Uzbek Cyrl scanlarda ishladi; rus/eng tabiiy scan accuracy'i sinalmadi. Bo'sh HTML heading kosmetik cleanupi ochiq.

### PLAN uchun keyingi takliflar — hali tasdiqlanmagan

- 13 review bo'yicha source visual gold/reference bilan format/til kesimida acceptance siyosati; OCRda band/raqam/sana uchun kengroq gold va CER/WER/provenance tekshiruvlari. Confidence thresholdlari yakuniy accuracy mezoni emas.
- PDFning barcha til aliaslarini isbotlash va layout/table tiklash usulini tanlash; ma'lumot yo'qolishi reviewda qolishi kerak.
- Full executiondan oldin adaptive date partition + count reconciliation va bounded run'dan tashqari durable ledger arxitekturasini kelishish. Besh jadval qarorini tasdiqsiz o'zgartirmaslik.
- Access/bulk shartlari, source drift/expiry va 1000 testga alohida ruxsatdan so'ng corpus request/duration modelini qayta hisoblash.

Faqat P2 topshirig'ida aniq berilgan qarorlar PLANga kiritildi. P0.1 takliflari, qo'shimcha jadvallar yoki production OCR acceptance avtomatik tasdiqlangan deb olinmadi.

### Request/duration bo'yicha chegaralangan hisob

Bu full import taxmini uchun yetarli corpus o'lchovi emas. Faqat kuzatilgan run2: **156/27 ≈ 5.78 request/sample group**, **470/27 ≈ 17.41 s/group**; aliaslar takror fetch qilinishi va OCR tarkibi ushbu nisbatga ta'sir qiladi. Agar keyinchalik N guruh ayni taqsimotda bo'lsa, shartli model `R≈R_discovery+5.78×N`, pacingning o'zi kamida `2×R` sekund; extraction/retry/manual review qo'shimcha vaqt. Bu **shartli ekstrapolyatsiya**, real full corpus total yoki va'da emas. Root countlarini qo'shib N deb olish noto'g'ri. P2da full request soni va import davomiyligi ishonchli aniqlanmadi.

## G. Final Recommendation

**P3: CONDITIONAL GO.** Laravel HTTP → source parser → validator → universal importer → PostgreSQL/Database Queue oqimi, bounded discovery/pagination/resume va idempotency kichik real sinovda ishladi. Keyingi tasdiqlangan scope sifatida ochiq quality/coverage masalalarini yopish va pending valid full-text misolini tekshirish mumkin. Bu P3ga ruxsat emas; scope va boshlanish uchun foydalanuvchining **aniq tasdig'i kerak**.

**Full import: NO-GO.** 13 review, global qamrov, PDF tillari/OCR certification, bulk access, masshtabli ledger va 1000 gate hali ochiq. Full/1000 import, production deployment, cron daily va P3 ishga tushirilmadi. P2 tugagach to'xtaldi.

Natijani qayta ko'rish: `php artisan documents:sync lex --report=1` yoki `--report=2`. Bu read-only report, yangi import dispatch qilmaydi. Real sinovni qayta boshlash uchun READMEdagi explicit live flag, 1..30 limit va bitta worker shart; bu hisobot ularni avtomatik ishga tushirish topshirig'i emas.
