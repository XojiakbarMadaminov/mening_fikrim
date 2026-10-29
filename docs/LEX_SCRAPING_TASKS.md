# Lex.uz scraper — bajarish rejasi va progress

**Status:** P5 controlled Corpus Collection Pilot safety budgetda yakunlandi:158NEW canonical,187document/433content,344verified+89needs_review; [P5_REPORT](P5_REPORT.md), [progress/evidence](p5/README.md). P4 Corpus Collection MVP yakunlandi: usable review content saqlanadi, verified text himoyalangan, bounded offline recheck mavjud. [P4_REPORT](P4_REPORT.md), [dalillar](p4/README.md).130test/581assertion;13oldreview guruh saqlandi, final16canonical unchanged.1000/full/cron va keyingi bosqichlar boshlanmagan.  
**Talablar manbasi:** `LEX_SCRAPING_PLAN.md`  
**Loyiha:** P1da noldan yaratilgan Laravel 13, PHP 8.5, PostgreSQL. Foundation saqlanadi; legacy application kodidan ko'chirmang. Development doim `mening_fikrim_active`, test doim `mening_fikrim_active_test`.

## Ishlash qoidasi (Codex / developer)

1. Avval `LEX_SCRAPING_PLAN.md`ni to'liq o'qi, keyin shu checklistni ko'r.
2. Eng birinchi tugallanmagan bosqichdan davom et; avvalgi bosqichdagi buzilgan testlarni ham qayta tekshir.
3. Checkbox faqat kod/tekshiruv real bajarilib, dalil ko'rsatilganda `[x]` bo'ladi.
4. Har bosqich tugagach, **bajarildi / test / muammo / keyingi qadam** yozuvi bilan progressni yangila.
5. Unknown endpoint yoki HTML selectorni taxmin bilan production qoidasi sifatida yozma. Avval haqiqiy request va sample bilan tasdiqla.
6. Hozircha AI, embedding, RAG, pgvector, frontend, admin, API implement qilma.
7. P3 tasdiqlagan 6 ta asosiy biznes jadval chegarasini saqla; Laravel `jobs`, `failed_jobs`, `cache`, `job_batches` zaruratga ko'ra framework infrastructure.
8. Http rate-limit va saytning foydalanish shartlariga rioya qil; server cheklovlarini aylanib o'tishga urinma.
9. P3 uchun aniq tasdiq olindi. P3dan keyin to'xta; yangi aniq tasdiqsiz P4, 1000 integration yoki full importni boshlama. Eski raqamlangan checklistdagi ish P2ga kiritilgan bo'lsa bu alohida qayd qilinadi; keyingi bosqich boshlangan hisoblanmaydi.

## P0. Texnik spike — katta importga kirishdan oldingi shart

- [x] Lex.uz qidiruv bo'limlari ro'yxati va coverage mapini tuz (`official`, `unique`, kerakli boshqa bo'limlar). — `nat/loc/int/tech/court/oliy` real GET; katta bo'limlar faqat 1-sahifa. Global coverage hali isbotlanmagan.
- [x] Active/pending filtrlari va qabul qilingan sana bo'yicha real HTTP requestlarni kuzat. — `Y/R/N`, `from/to`, `from_eff/to_eff`; `Y` pending'ni ham qamraydi, `N` eski tahrir bo'lishi mumkin.
- [x] Pagination, total count, cursor va sana intervalini bo'lish qoidalarini isbotla. — Kichik segmentlarda POST/Viewstate: official 89/89, nat oy 80/80; ikki intervalda 6 ID overlap. Katta segment cap/global completeness ochiq.
- [ ] Listingdan ID/url, status va til variantlarini olish ishonchliligini test qil.
  - Qisman: ID/url va HTML til graph'i ishladi. `unique` status filtri natijani o'zgartirmadi; PDF menu haqiqiy tillarni bermaydi; PDF/card status ziddiyati bor. Ishonchlilik mezoni o'tmagan.
- [x] HTML'dagi joriy tahrir va status ko'rsatkichlarini aniqlab fixture saqla. — `#divCont`, `.lx_date_selected`, `card1`; real source fixturelari. Ziddiyatli status uchun umumiy yechim hali yo'q.
- [x] `8557098` bo'yicha haqiqiy PDF resursini / viewer oqimini aniqlab, PDF magic bytes va content type tekshir. — `/pdffile/8557098`, HTTP 200/application/pdf/%PDF, 9 sahifa, cookie/auth headersiz ham ishladi.
- [x] `pdftotext` bilan text layer, Tesseract bilan skanlangan 1-2 test hujjatni sinab ko'r. — 6 text-layer PDF, 2 tabiiy scan: 19+17=36 sahifa OCR.
- [x] PDF/OCR matnida jadval, matn tartibi, barcha sahifa va shrift muammolarini yoz. — 101 PDF sahifa; PDF struktura, jadval/rasm/OCR va script farqi reportda; sifat kafolati berilmadi.
- [x] Lex.uz qidiruv/RSS orqali yangi tahrir va bekor bo'lish signalini tekshir; topilmasa recheck fallbackga tayan. — RSS 129 item va real per-document revhis tekshirildi; universal delta-feed tasdiqlanmadi.
- [ ] Sayt foydalanish shartlarini va avtomatlashtirilgan yuklab olish talablari / robots / rasmiy API imkonini tekshir.
  - Qisman: axborot/agreement/user guide/offline/statistic o'qildi; robots 404, public bulk API aniqlanmadi. Barcha turdagi bulk foydalanish ruxsati va avtomatlashtirish chegaralari tasdiqlanmagan; checkbox ochiq.
- [x] Har xil 30 hujjatdan fixture katalogi tuz: HTML, PDF, OCR, turli tillar, kodeks, qaror, ilova, jadval, active/pending. — 30 seed, 62 to'liq matn varianti, 15 metadata-only sahifa; raw original doimiy saqlanmaydi. 30 hujjatning satrma-satr to'liq manual QA'si bajarilgani da'vo qilinmaydi.
- [x] Spike xulosasini hujjatlashtir: qamrov, endpoint, risklar, kerakli selectorlar, asosiy blockerlar. — [P0_REPORT.md](P0_REPORT.md), [dalillar](p0/README.md), 16 test.

**Gate P0:** Real HTTP yo'llari va PDF bilan ishlash isbotlanmaguncha `full import`ni boshlama.

**P0 natijasi (2026-10-28):** 30/30 extraction, 11/30 cheklangan strict quality pass, 19 review; 23 active, 3 pending, 3 inactive, 1 unresolved. HTTP/PDF yo'llari ishladi, ammo global qamrov/tillar va sifat ishonchliligi sabab umumiy P0 acceptance **NO-GO**. Takliflar hisobotda; PLAN qarorlari o'zgartirilmadi. **Keyingi qadam: foydalanuvchi qarori; P1ni boshlashga hali ruxsat yo'q.**

## P0.1. Critical Issues Resolution

- [x] PLAN, TASKS, P0_REPORT va mavjud P0 natija/fixturelarini tekshir. — 30 manifest/fixture, 77 Markdown hash tekshirildi; avvalgi mexanizmlar qayta ishlatildi.
- [x] Haqiqiy reciprocal til havolalari va metadata bilan canonical algoritmini sinab ko'r. — 4 guruh, 15 ID, 42 directed edge; missing optional field haqiqiy conflict'dan ajratildi; `abs(id)` ishlatilmadi.
- [x] Mavjud 4 jadval va metadata mapping bilan dublikat/atomiklik modelini tekshir. — Temporary SQLite: 24 kelish tartibi, 96 chaqiruv, 1 document/3 full-text content, created 1/unchanged 95; review va rollback'da snapshot saqlandi. Bu PostgreSQL concurrency testi emas.
- [x] 8 bo'lim, tarixiy sana intervallari, overlap va barcha til discovery'ni bounded test qil. — 17 core case (12 to'liq), 1992/2000/2010/2020/2026; sud F/H/J Latin filter gap tasdiqlandi. Til cheklovisiz strategiya taklif qilindi.
- [x] ASP.NET pagination va katta query oxirini real POST bilan qayta tekshir. — 15 787 record/790-sahifa; 55 971 record/2799-sahifa reachable. O'rta sahifalar to'liq yurilmadi.
- [x] Discovery checkpoint/replay/dedup va invalid/cycling state guardini tekshir. — Real resume 80 unique/20 duplicate skip; tarixiy split 22/22; lokal fault testi checkpointni advance qilmadi.
- [ ] Haqiqiy token expiry va barcha query/corpus global pagination/cap/coverage'ni tasdiqla. — Yangi session'da eski state ishladi; real expiry kuzatilmadi. Global qamrov ochiq.
- [x] Y/N/R, future date, localized label va date conflict validationini test qil. — `-8553692` text/card conflict IDga xos istisnosiz aniqlandi; Amalda faqat shu ID uchun live listing Y corroboration bilan; biznes status qo'shilmadi.
- [x] Avvalgi 19 review sample'ning barcha taklif etilgan HTML variantlari va 8 PDFni qayta tekshir. — 28 full HTML variant/7 metadata-only; 101 PDF sahifa/36 qayta OCR sahifa.
- [x] Numeric superscript, complex table cell/span inventory, rasm, partial layer va OCR confidence/gold qoidalarini sinab ko'r. — 6 yangi pass, 13 review. P0 eski 11 pass qayta parse qilinmagan; 17/30 faqat carry-forward bilan kompozit hisob.
- [ ] Qolgan 13 review, PDF alias language coverage va hujjat darajasidagi OCR aniqligini full import uchun qabul qilinadigan darajaga yetkaz. — Yuqori confidence auto-accept emas; 2 kichik gold excerpt butun hujjatni sertifikatlamaydi.
- [x] Safe-update, missing language, shrinkage rollback va strict completion kontraktlarini test qil. — 37 P0.1 test + 16 oldingi regressiya test o'tdi; raw fayllarsiz qayta test ham o'tdi.
- [ ] PostgreSQL JSONB/lock/alias uniqueness, Laravel queue/dispatch atomicligi va 1000 integration gate'larini real muhitda tekshir. — P1da PostgreSQL JSONB/identifier UNIQUE/source lock, haqiqiy race/rollback va after-commit queue dispatch tekshirildi. P2da bounded discovery dispatch/checkpoint reconciliation ham tekshirildi; masshtabli ledger va 1000 integration gate hali ochiq.
- [x] [P0_1_REPORT.md](P0_1_REPORT.md) va [PLAN takliflari](P0_1_PLAN_PROPOSALS.md)ni tayyorla. — Asosiy PLAN/12 qaror o'zgartirilmadi; yangi jadval/ustun/status kiritilmadi.
- [x] Foydalanuvchidan P1 boshlanishiga aniq tasdiq ol. — 2026-10-28 yangi P1 topshirig'i A/B/C qarorlarini ham tasdiqladi.

**P0.1 xulosa:** P1 foundation uchun **CONDITIONAL GO**, full import uchun **NO-GO**. Bu ruxsat emas. Tasdiqlanmagan architecture takliflari alohida faylda, qolgan xavflar reportda. Laravel/production DB/full import boshlanmagan.

## P1. Laravel 13 Foundation & Database Architecture — foydalanuvchi tasdiqlagan kengaytirilgan scope

- [x] PLAN, TASKS, P0/P0.1 hisobotlari, takliflar va saved fixture/test dalillarini o'qi; live P0/P0.1ni takrorlama.
- [x] PHP 8.5, Composer, PostgreSQL va extension compatibilityni tekshir; Laravel 13 loyihasini noldan yarat.
- [x] Mavjud bazani saqla; alohida local P1 va `_test` bazalarini sozla. Real credentiallar faqat gitignored `.env` fayllarida, example/Markdownda emas.
- [x] `document_sources`, `documents`, **`document_identifiers`**, `document_contents`, `scrape_runs` migrations; JSONB, FK, UNIQUE va kerakli indekslar.
- [x] Besh universal Eloquent model, relationships/casts; active/pending/inactive enum va scopes; idempotent source seeder.
- [x] Identifier mapping, reciprocal source links + matching requisites, stable canonical anchor; fail-closed conflicting mappings.
- [x] Universal importer: mustaqil tillar, metadata-only skip, normalized SHA-256, old good text protection, transaction/rollback, idempotence.
- [x] Source metadata effective date precedence; PDF/body mismatch warning; unknown/ichki zid metadata validation failure.
- [x] `last_checked_at` faqat documentning barcha required full-text tillari valid bo'lganda yangilansin.
- [x] Database queue/cache infra; `ImportDocumentJob` offline candidate qabul qiladi; after-commit dispatch. Live discovery/worker/schedule ishga tushirilmasin.
- [x] `config/scraping.php`, `.env.example`, `.env.testing.example`; timezone Asia/Tashkent; retry_after > job timeout guard.
- [x] Real PostgreSQL suite: FK/UNIQUE, identity, namespaces, concurrent processes, rollback, independent languages, hashes, status, strict completion.
- [x] Pint/PHPUnit yakuniy tekshiruvlarini qayd et.
- [x] README va portable importer contract yoz; Poppler/Tesseract keyingi parsing bosqichiga tegishliligini ko'rsat.
- [x] PLANga faqat aniq tasdiqlangan A/B/C qarorlarini kirgiz; qolgan P0.1 takliflarini avtomatik tasdiqlama.
- [x] `P1_REPORT.md`, migration/test dalillari va P2 gate xulosasini tayyorla; natijani terminalda ko'rsat.
- [x] P2 boshlash uchun foydalanuvchining yangi aniq tasdig'ini ol. — 2026-10-28 P2 topshirig'i, mavjud P1ni saqlash va 30 tagacha real test.

**Gate P1:** yangi Laravel 13/PHP 8.5, besh biznes jadval bilan local migration va haqiqiy PostgreSQL testlari o'tadi; no live sync/full import. P2 tasdig'i P1 yakunining sharti emas — majburiy to'xtash chegarasi.

## P2. Lex.uz Scraping Implementation & Integration — yakunlandi

P2 topshirig'i eski checklistdagi parser/importer/queue ishlarining bounded qismini shu bosqichga kiritdi. P2 yakunida P3ga ruxsat kutilgan; keyingi P3 topshirig'i uni tasdiqladi. Dalillar [P2_REPORT](P2_REPORT.md), [p2 katalogi](p2/README.md)da.

- [x] Oldingi PLAN/contract/hisobot/fixturelarni o'rgan, P1ni qayta yozma. — 214 tarixiy fayl hash'i o'zgarmagan.
- [x] Avval mavjud bazalarni tekshir; fixed active/test nomlarini sozla, P1 schema bilan taqqosla. — Active bo'sh edi; 7 migration/2 seed, columns/constraints/indexes teng; boshqa bazalar saqlandi.
- [x] Test actual PG database nomini migrationdan avval tekshirsin; developmentni tozalamasin; credentiallar faqat ignored local envda.
- [x] DocumentScraper, LexUzScraper, LexUzParser; HTTP common pacing >=2 s, timeout/retry/429/403, redacted diagnostics.
- [x] nat/loc/int/tech/court/oliy til filtrisiz real discovery; current ASP.NET hidden fields/postback, cycle/count/prefix guardlar.
- [x] Bounded pagination va fresh-session replay/dedup. — Real tarixiy 72/72, 4 page, 20 duplicate skip; actual expiry kuzatilmagan.
- [x] Reciprocal links/rekvizitlar bilan identity va identifiers; metadata-only skip; PDF global UI til menyusini ignore qil.
- [x] Source metadata status/effective_at ustuvorligi; unknown fail-closed; body mismatch warning; mustaqil tillar.
- [x] HTML bob/modda/band/ilova, Unicode superscript va oddiy jadval; complex span/image/math review; token fidelity.
- [x] Haqiqiy viewer resource'dan PDF bytes; CT/magic/page accounting; konservativ Markdown va structure/language review.
- [x] Page-level Poppler geometry/Tesseract OCR; confidence/critical-token signal; uncertified OCR guard; resource limits/cleanup.
- [x] Parser → P1 Validator → universal Importer → PG; transaction va eski yaxshi matn himoyasi.
- [x] Real Database Queue; checkpoint/queue bir transactionda, alohida import job, retry/failed tracking/outcome reconciliation.
- [x] documents:sync full/daily/recheck, explicit 1..30 limit, sample/section, report/resume; unlimited/1000 guard.
- [x] Daily/recheck selection va o'chirilgan 02:00 Asia/Tashkent schedule konfiguratsiyasi; cron yoqilmagan.
- [x] Offline suite, P1 regression va 30 real guruh. — 77 test/306 assertion; 17 valid/13 review, 0 final technical failure.
- [x] Initial active/pending sample va alohida real-source inactive update/OCR safe-update. — Dev 15 active/37 content; test DBda 2 controlled state.
- [x] Real worker restart/resume, ayni 27 guruh qayta import; 15 unchanged, ID/hashlar teng. Discovery→queue alohida 1 verified alias sinovi.
- [x] Pint/Composer/migration/Markdown/hash audit; temp/source fayllarni tozalash.
- [x] P2_REPORT, PLAN/TASKS/README va terminal natijalari.
- [ ] 13 reviewni qabul qilinadigan sifatga yetkazish; PDF tillari/strukturasi va document-level OCR accuracy'ni tasdiqlash.
- [x] P3da real valid pending import: yangi 8548643 guruhi, 3 til, 2027-01-01 metadata; eski uch pending review o'z-o'zidan valid qilinmadi.
- [ ] Global corpus coverage, haqiqiy token expiry va bulk access cheklovlarini tasdiqlash.
- [x] P3 scope'i/boshlanishi uchun foydalanuvchi tasdig'i — 2026-10-28 P3 topshirig'i.

**Gate P2:** bounded integration ishladi; quality/global gates ochiq. P3 **CONDITIONAL GO**, full import **NO-GO**. Bu keyingi bosqichga ruxsat emas.

## P3. Scraping Quality, Durable Tracking & Performance — tasdiqlangan scope

- [x] PLAN/TASKS/P0/P0.1/P1/P2/contract va fixture/auditlarni o'qi; P1/P2 source mexanizmlarini saqla.
- [x] Fixed development/test DB, eski data va failed_jobsni saqla; faqat migration bilan oltinchi jadval.
- [x] scrape_run_items model/migration/FK/UNIQUE/index/CHECK, nullable document, opaque source ID.
- [x] Workflow, receipt/token/lease/attempts, retry_wait va explicit stale/failed recovery.
- [x] P2 bounded checkpoint ledgerini DBga ko'chir; historical failed counter provenance'ini saqla.
- [x] Accepted/review/technical failure: review durable va normal success, unexpected failure retries/exhaustion.
- [x] Valid tillar/old good text guard, partial technical retry va created actionni saqlash.
- [x] Canonical alias reconciliation: har source ID itemda, verified alias skip; ikki real worker bilan duplicate yo'q.
- [x] Item/queue/checkpoint atomicligi; terminal outcome/counter transaction consistency; strict completion deferred DB trigger.
- [x] Source registry, query signatures, inclusive adaptive dates, bounded DFS frontier, union/dedup va single-day coverage gap.
- [x] Real historical identical-query comparison; boshqa yil/til/section natijalarini drift deb hisoblamaslik.
- [x] HTML empty headings, recursive/nested cell handling, coordinate/span inventories, image/math/cleanup review.
- [x] PDF validation levels, page accounting, optional pdfplumber ikki tolerance diagnostikasi; 8557098 9×5 fixture qayta isbotlandi, universal acceptance emas.
- [x] Source-discovered alternate scan PDF 17+19 page OCR; physical script/confidence/critical token/provenance.
- [x] CER/WER existing gold; suspicious word regions/line-height hints; missing/merged line proof unknown deb belgilangan.
- [x] Majburiy 13 reviewni real qayta bahola va P2/P3 yonma-yon yoz; unresolvedni valid qilma.
- [x] Yangi haqiqiy pending hujjatni top/import qil — 8548643 guruhi, uz-Latn/uz-Cyrl/ru.
- [x] Offline matrix 1/2 worker × shared 2000/simulated1000 ms; actual two-worker activity va OCR contention, CPU/RAM/latency/throughput.
- [x] Live worker=1, >=2000 ms, <=30 distinct groups va campaign request budget; full/1000 yo'q.
- [x] Graceful restart/resume, controlled crash/rollback, stale processing, duplicate dispatch va exhausted technical retry testlari.
- [x] CLI run/items/status, source/canonical, review/technical failed, pending framework jobs, language/quality gates.
- [x] Regression, real PostgreSQL concurrency va queue testlari; normal review uchun yangi failed_jobs=0.
- [x] SCRAPING_BENCHMARK, README/contract/PLAN/TASKS yangilanishi.
- [x] P3_REPORT, final audit/cleanup va terminal yakuniy natijalari — `p3/final_terminal.txt`, 289 historical hash saqlangan, private temp o'chirilgan.
- [ ] 13 reviewni document-level acceptancega yetkazish; PDF language/order/table/image va OCR certification hali ochiq.
- [ ] Global coverage/access/cap/expiry, masshtabli query/fairness va alohida 1000 integration gate.
- [x] P4 boshlanishi uchun foydalanuvchining yangi aniq tasdig'i — Corpus Collection MVP topshirig'i.

Manual certification va katta query GIN/profiling — reportdagi takliflar; auto-admission yoki yangi jadval tasdiqlangan hisoblanmaydi. P4/1000/full explicit tasdiqsiz boshlanmaydi.

## P4. Corpus Collection MVP — tasdiqlangan yangi scope

- [x] Baseline110/443 va saqlashni bloklayotgan kod yo'lini hujjatlashtirish.
- [x] Content quality/provenance/candidate fieldlari, non-destructive migration va legacy proof backfill.
- [x] Usable review textni saqlash; empty/invalid extractionni fake contentga aylantirmaslik.
- [x] Verified matnni pasaytirmaslik; past sifatli nomzodni pending_candidate'da saqlash.
- [x] Legal status va content quality mustaqil; canonical identity/til UNIQUE saqlangan.
- [x] Local bounded/dry-run documents:quality-recheck, no network/deletion.
- [x] Focused va complete offline regression; migration compatibility testi.
- [x] Development migration preservation audit, bounded real13review import va repeat hash verification — all40original/all63hash stable,29document/82identifier/63content.
- [x] P4_REPORT, yakuniy test/formatter/audit, README/PLAN/contract va terminal natijalari —130/581, P0offline16/P0.1offline37,286live×200,min2.000029156s.
- [ ] Global/1000/full acceptance — bu P4 scope'iga kirmaydi, alohida ruxsat kerak.

### Tarixiy importer checklisti — P1 foundation, P2 bounded integration

- [x] P1 foundation: `DocumentImporter` sourcega bog'liq bo'lmagan inputni qabul qiladi.
- [x] P1 foundation: identifier UNIQUE `(source_id, external_id)`, idempotent transaction.
- [x] P1 foundation: har til uchun normalized SHA-256 hash, updated/unchanged; mustaqil validate/save.
- [x] P1 foundation: faqat current content row, tarixiy nusxa yaratilmaydi.
- [x] P1 foundation: source metadata ustuvor pending/active/inactive; matn sanasi mismatch warning.
- [x] P1 PostgreSQL testi: existing document inactive bo'lganda content saqlanadi.
- [x] P1 fixture testi: invalid PDF/OCR eski matnni bosmaydi; valid boshqa tillar saqlanadi. Production parsing hali yo'q.
- [x] P1 PostgreSQL testi: ikkita alohida PHP process haqiqiy lock contention bilan bitta documentga yaqinlashadi.

- [x] P2 bounded adapter/parser chiqishini universal importer bilan integratsiya qilish.
- [ ] Production/full-scale quality va keyingi alohida tasdiqlangan gate'larni bajarish.

**Tarixiy foundation gate:** P1/P2 bounded integration tayyor; production/full-scale acceptance ochiq. Hozirgi P4 Corpus Collection MVP scope'i yuqorida alohida yakunlangan.

## P5. Corpus Collection Pilot — yangi aniq tasdiqlangan scope

- [x] Actual development baseline,9migration,queue/cache/pacing/schedule va preservation audit.
- [x] Existing130/581 regression va focused pilot controls; prelive142/615,final143/619,Pint,P0offline16/P0.1offline37 PASS.
- [x] Runlararo database-cache budget4000, per-run400/1..30, single-worker>=2000ms saqlandi; stop latch va terminal resumeni rad etish.
- [x] New canonical count baseline IDlardan; aliases/existing/languages/repeats targetga qo'shilmaydi;30→100→200 gate.
- [x] Olti real root/date partition, stable replay va complete/incomplete count dalili; global coverage claim yo'q.
- [x] Bounded worker stop/resume receipt-preservation va3group repeat live tekshirildi.
- [x] Stage B:30NEW canonical +integrity gate; stage_b_results.json,0technicalfailure.
- [x] Stage C:100NEW canonical +integrity gate;stage_c_results.json,0technicalfailure.
- [x] Stage D:158NEW canonicalda preflight360distinct-source-IDguard tugadi;1229/4000HTTP,0technicalfailure. 200targetga yetildi deb ko‘rsatilmadi.
- [x] Final DB/quality/pacing/performance audit va P5_REPORT/final_summary;530historicalhashsaqlandi,min2.000131773s,old24failedjobunchanged.
- [x] Exact200canonical gate P5.1da alohida ruxsat/campaign bilan yakunlandi:158P5+42P5.1=200. Eski P5 stop/budget/ledger o'zgarmadi;dalil P5_1_REPORT.md.
- [ ] Alohida1000pilot/full/scheduling ruxsati — P5 bunga ruxsat bermaydi.

Quyidagi full-discovery roadmap checklisti pilot bilan global completed deb belgilanmaydi.

## Tarixiy P5 roadmap. Full discovery va queue orchestration

- [x] P2 bounded scope: Sana intervallari va hujjat toifalari bo'yicha discovery algorithm.
- [ ] Natijalar ko'p bo'lsa adaptive interval + to'g'ri pagination.
- [x] P2 bounded scope: Discovery checkpoint/resume, unique IDlarni qayta yuklamaslik.
- [x] P2 bounded scope: `DiscoverDocumentsJob` / `ImportDocumentJob` implement qil.
- [x] P2 bounded scope: Queue retry, failed job, run_id bog'lanishi, per-source lock.
- [x] P2 bounded scope: `scrape_runs` unique progress statistikasi hamda strict completion reconciliation.
- [x] P2 `documents:sync lex --mode=full --limit=30`, report/resume.
- [ ] `--limit=1000` va cheksiz full execution — P2da ataylab taqiqlangan.
- [x] P2 bounded scope: Import o'rtasida worker restart bo'lganda davom etish testi.
- [ ] 1000 hujjatlik sinov: audit, errors, timeouts, no duplicates.

**Gate P5:** P2 qisqa testlari ishlaydi; adaptive partition, masshtabli ledger/global coverage va 1000 gate ochiq.

## P6. Hybrid Sync + Scheduler

- [x] P2 bounded scope: Daily discovery: oxirgi 7 kun overlap va yangi hujjatlar.
- [ ] Manbada ko'rinadigan tahrir va status signalini kuzatish; bo'lmasa recheck.
- [x] P2 bounded scope: Pending sanasi yetganda manbadan qayta tekshirib active qilish.
- [x] P2 bounded scope: Active hujjatlarni 90 kunlik rolling recheck (`last_checked_at`).
- [x] P2 bounded scope: Inactive avvalgi matnini saqlagan holda odatiy recheckdan chiqarish.
- [x] P2 bounded scope: Scheduler 02:00 Asia/Tashkent, `withoutOverlapping`, lock.
- [x] P2 bounded scope: Local 1 worker, 2 soniya umumiy request oralig'i, timeout/retry/backoff.
- [x] P2 bounded scope: 429 `Retry-After`, 403 bloklanish va 5xx testlari.

- [ ] Real uzoq muddat daily/recheck va full/daily overlap acceptance; scheduler faollashtirish uchun alohida ruxsat.

**Gate P6:** Bounded selection/configuration testlangan; real daily va full bilan concurrency acceptance ochiq.

## P7. Report / Strict Completion / Release

- [x] P2 terminal report: discovered, processed, created, updated, unchanged, failed, review, open items, status/format/til/duration.
- [x] P2 bounded scope: `completed`, `completed_with_errors`, `failed` holatlarini test qil.
- [ ] Barcha segmentlar, pagination, til variantlari va failed_jobs qamrovini solishtir.
- [x] P2 bounded scope: Missing language, invalid PDF/OCR, huge text reductionni reportda ko'rsat.
- [ ] Qayta ishga tushirish (resume/retry) qo'llanmasi va server Supervisor/cron namunasi.
- [ ] SQL indekslarni real query ishlatishiga qarab qayta tekshir, ortiqchasini qo'shma.
- [ ] Smoke test, 30 manual QA va 1000 integration natijalarini hujjatlashtir.
- [ ] Full importni ishga tushirishdan oldin P0–P7 gate'lari qondirilganini tasdiqla.

## Kelajak: V2 / AI — hozir implement qilinmaydi

- [ ] `document_chunks` migration + structure-aware chunking.
- [ ] Embedding model, dimensiya va `chunk_embeddings` yoki vector column haqida alohida qaror.
- [ ] Content hash o'zgarsa chunklarni qayta hisoblash.
- [ ] `status = active` va sana filtrli RAG retrieval.

## Progress jurnal

| Sana | Bosqich | Natija | Test dalili | Bloker / keyingi qadam |
|---|---|---|---|---|
| 2026-10-28 | Rejalashtirish | 12 ta arxitektura qarori kelishilgan; kod boshlanmagan | Chatdagi tasdiqlar | P0 Spike |
| 2026-10-28 | P0 yakuniy tekshiruv | 30 seed vaqtinchalik import; 22 HTML/8 PDF, 2 scan/36 OCR sahifa; 30 extraction, 11 quality pass, 19 review | `P0_REPORT.md`, `p0/requests.jsonl` (171 live urinish), `p0/test_output.txt` (16 test OK), fixture/manifest | NO-GO: global coverage, PDF tillari, metadata ziddiyati, struktura sifati va bulk access ochiq. P1 boshlanmagan; foydalanuvchi tasdig'i kutiladi. |
| 2026-10-28 | P0.1 | 4 verified identity guruh; 19 review retest ichidan 6 pass/13 review; til filteri qamrov xatosi topildi; xavfsiz update/resume modeli | `P0_1_REPORT.md`, `p0_1/requests.jsonl` (139×200), `p0_1/test_output.txt` (37), `p0_1/p0_regression_output.txt` (16), temporary 4-table audit | P1 CONDITIONAL GO, full import NO-GO. PLAN takliflari tasdiqlanmagan; P1 uchun aniq ruxsat kutiladi. |
| 2026-10-28 | P1 | Laravel 13.39.0/PHP 8.5.11, 5 biznes jadval; identifier mapping, mustaqil tillar va metadata date precedence | `P1_REPORT.md`, `p1/junit.xml`: 46 test/187 assertion, 0 failure/error; 2 real PHP process/2 PG lock waiters, rollback; 7 migrations Ran | P2 texnik GO, aniq tasdiq kutiladi; full import NO-GO, alohida ruxsat kerak. |
| 2026-10-28 | P2 | Fixed active/test DB; HTTP/discovery/HTML/PDF/OCR/queue; 30 real guruh 17 valid/13 review, dev 15/45/37; repeat 15 unchanged | `P2_REPORT.md`, `p2/junit.xml`: 77 test/306 assertion; 371×200, min interval 2.00003091 s; PG/resume/safe-update | P3 CONDITIONAL GO, aniq tasdiq kutiladi. Full NO-GO: quality/global/access/1000 gate ochiq. |
| 2026-10-28 | P3 | 6-jadval/durable112item, review/technical ajratildi; real16group:3accepted/13review; pending3til yaratildi; dev16/48/40; repeat3unchanged+3alias | `P3_REPORT.md`, `p3/final_junit.xml`:110test/443assertion;289×200,min2.000021697s;4offlinebenchmark,2PGprocess,restart/resume,all40hashstable,old24failedjobspreserved | P4 CONDITIONAL GO, yangi aniq tasdiq kutiladi. 1000/full NO-GO; quality/coverage/access ochiq, cron/live defaultfalse. |
| 2026-10-28 | P4 | Usable review persistence, per-content quality/provenance, protected candidate va local recheck.13oldreviewgroup persisted:12needs_review+1currentHTMLverified; dev29/82/63. Final16group unchanged+3alias | `P4_REPORT.md`,130test/581assertion, legacy16+37offline;286×200,min2.000029156s;all40/all63hash stable;9migrations,old24failedjobs preserved | Collection MVP bajarildi.21content reviewda, global/1000/full gate ochiq; keyingi ish alohida tasdiq bilan. |
| 2026-10-28–29 | P5 pilot |158NEW canonical/370newcontent;final187/509/433,344verified+89needs_review;30/100gatespassed,360sourceIDbudgetda stop;200exactopen |143test/619assertion,P0offline16/P0.1offline37,Pint;1229×200,min2.000131773s;34pagePDF+7pageOCR,0technicalfailedjob,all63originalhash/530historicalfilepreserved |1000pilot uchun NO-GO:avval42NEWsupplementation,identity/languagelabelQA,incompleteoliydatepartition;noautomaticresume/full/schedule. |


## P5.1 — Pilot completion (2026-10-29)

- [x] Actual187/509/433 baseline,P5 stopped state/old ledger and643historical file preservation.
- [x] Language availability JSONB evidence independently from quality,unknown preserved,no fake content;no migration/new table.
- [x] Ruscha/Русча normalization with actual P5 diagnostics,raw labels retained;fallback guard/strict identity preserved.
- [x] Document quality vs separate language_coverage_status,confirmed failed extraction visible,verified text protected.
- [x] Inclusive bounded date subdivision8pages/depth10/63partitions,checkpoint/replay/dedup/count reconciliation. Oliy2020:Y1429/1429,15leaf/29resolvednode;global coverage still open.
- [x] Identity investigation:1-ҚҚ vs1-КК and missing reciprocal PDF evidence;no speculative merge,five actual source IDs retained for manual review.
- [x] Separate campaign42NEW,20→42 audit gates,180source/1800HTTP limits;actual114selected/414HTTP,1worker,min2.000140510s.
- [x] 42additionalcanonical/108newcontent persisted (105verified/3review),DB229/625/541,449verified+92review.
- [x] Worker receipt-safe restart,repeat import hash/count stability and every-stage integrity verified;0technicalfailure,24historicalfailedjobs preserved.
- [x] Two real PDF/OCR controls repeated;34pagePDF/7pageOCR unchanged and review retained. New unique-format diversity remains a documented gap.
- [x]172Laraveltests/719assertions,Pint,P0offline16/P0.1offline37PASS;test schema restored after suite,no development resets.
- [x] P5_1_REPORT.md and machine-readable p5_1 evidence;real/synthetic/retained examples explicitly separated.
- [ ] Fresh live confirmed-not_available and available-but-failed language samples;current counts0/0,not fabricated.
- [ ] Manual identity resolution,additional unique PDF/OCR/manual quality QA,source access/storage/long-run durability gates.
- [ ] P6/1000/full/scheduling:separate explicit authorization required;not started.

|2026-10-29|P5.1|42NEW,combined200;229docs/625IDs/541texts,449verified+92review;oliy2020 coverage1429/1429|172tests/719assertions,Pint,16+37offline;414×200,882.156s,old433hashes/P5ledger preserved|P6 CONDITIONAL GO with explicit authorization;PDF/OCR diversity,identity/access/durability gaps remain.|
