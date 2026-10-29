# P0 Technical Spike — Lex.uz

**Sana:** 2026-10-28, Asia/Tashkent. **Qaror: NO-GO — P0 qabul mezonlari to'liq o'tmadi.** Tekshiruv va 30 hujjatlik tajriba yakunlandi, lekin global coverage, PDF tillari, ayrim metadata ziddiyatlari va strukturaviy sifat ochiq. P1 hozir tavsiya etilmaydi; foydalanuvchining aniq tasdig'isiz keyingi bosqich boshlanmaydi.

Laravel loyihasi yaratilmagan. Eski kod ishlatilmagan. Production PostgreSQL'ga ulanish va full import bajarilmagan. Yangi P0 skriptlari faqat vaqtinchalik SQLite hamda derived Markdown bilan ishladi. Tasdiqlangan arxitektura va `LEX_SCRAPING_PLAN.md` o'zgartirilmadi.

## 1. Dalillar va usul

171 ta Lex.uz HTTP urinishi yozib olindi: **169 × HTTP 200**, robots uchun **1 × 404**, ataylab juda qisqa timeout qo'yilgan **1 ta client ReadTimeout**. Response hajmi jami 61 118 599 bayt. Nominal interval 2 soniya, bitta ketma-ket worker. Wall-clock logidagi eng kichik start farqi 1.997196 soniya; millisekund aniqlikdagi farqni qat'iy server limiti haqidagi dalil deb qabul qilmaymiz. HTTP 200 latency medianasi 0.031 s, maksimumi 3.442 s; bu kichik, ko'p keep-alive/cache javobli namuna, umumiy server SLA'si emas.

Dalillar:

- [HTTP log](p0/requests.jsonl): haqiqiy URL, metod, POST target, vaqt, response turi/hajmi/hash, xatolar. Viewstate qiymatlari hash qilingan.
- [Discovery](p0/discovery.json), [yakuniy probe](p0/final_probes.json), [access/RSS](p0/access_feed.json).
- [30 hujjat manifesti](p0/sample_manifest.json), [statistika](p0/import_stats.json), [vaqtinchalik DB auditi](p0/temp_db_audit.json).
- [Qisqa source fixturelari](p0/source_fixtures.json), [Markdown natijalari](p0/markdown/), [16 test natijasi](p0/test_output.txt), [qaytarish yo'riqnomasi](p0/README.md).

Raw HTML/PDF va render rasmlari vaqtinchalik katalogda tekshirildi; doimiy artefaktlar — Markdown, metadata, hashlar va qisqa acceptance fixturelari. Barcha 30 seed uchun avtomatik strukturaviy tekshiruv bor. **30 hujjatning har bir satrini to'liq qo'lda ko'rib chiqish bajarilgani da'vo qilinmaydi.** Vizual tekshiruv: `8557098` PDF 9-sahifa, `-8553692` skan 1-sahifa, `-8553258` skan 17-sahifa; qo'shimcha sarlavha/band va boshlanish/oxirgi matnlar DOM/Markdown orqali solishtirildi.

## 2. Discovery natijalari

### Ishlagan bo'limlar va coverage map

Manzillar sayt HTML'idagi `goSearch(num)` funksiyasidan olindi va haqiqiy GET bilan tekshirildi. `official` va `unique` berilgan rejaning seed manzillari edi.

| So'rov/bo'lim | Manba qaytargan total | Ushbu run'da tekshirilgan qamrov |
|---|---:|---|
| [`official?lang=4&pub_date=month`](https://lex.uz/uz/search/official?lang=4&pub_date=month) — rasmiy e'lonlar | 89 | 5 sahifa, 89 unique ID, oxirigacha |
| [`nat?lang=4&status=Y`](https://lex.uz/uz/search/nat?lang=4&status=Y) — milliy hujjatlar | 15 787 | Birinchi 20 natija |
| [`nat?status=Y`](https://lex.uz/uz/search/nat?status=Y) — til belgilanmagan | 55 971 | Birinchi sahifa; til variantlari alohida natijalar |
| [`nat?lang=4&status=N`](https://lex.uz/uz/search/nat?lang=4&status=N) | 56 | Birinchi sahifa, eski tahrir namunasi tekshirildi |
| [`nat?lang=4&status=R`](https://lex.uz/uz/search/nat?lang=4&status=R) | 12 690 | Birinchi sahifa, 2 inactive namuna tekshirildi |
| [`loc?lang=4&status=Y`](https://lex.uz/uz/search/loc?lang=4&status=Y) — mahalliy hokimiyat | 10 266 | Birinchi sahifa, 3 seed |
| [`int?lang=4&status=Y`](https://lex.uz/uz/search/int?lang=4&status=Y) — xalqaro | 1 623 | Birinchi sahifa, 3 seed |
| [`tech?lang=4&status=Y`](https://lex.uz/uz/search/tech?lang=4&status=Y) — texnik hujjatlar | 574 | Birinchi sahifa, 3 seed |
| [`court?lang=4&status=Y`](https://lex.uz/uz/search/court?lang=4&status=Y) — sud amaliyoti/qarorlari | 564 | Birinchi sahifa, 3 seed; barcha sud subtoifalari qamrovi noma'lum |
| [`oliy?lang=4&status=Y`](https://lex.uz/uz/search/oliy?lang=4&status=Y) — Oliy Majlis | 10 298 | Birinchi sahifa, 3 seed |
| [`unique?lang=4&status=Y`](https://lex.uz/uz/search/unique?lang=4&status=Y) | 58 018 | Filter ishonchsiz, quyidagi izohga qarang |

`unique` uchun `status=Y`, `R`, `N` so'rovlari **bir xil total va bir xil dastlabki 20 ID**ni qaytardi; `R/N` natijalarida ham `status_code_y` bor. To'liq HTML hashlar teng emas, chunki sahifadagi boshqa state o'zgaradi. Shuning uchun ushbu endpointning status filtri ishlaydi deb hisoblash mumkin emas.

`official` oylik ro'yxati arxivning o'rnini bosa olmaydi. Bir xil oy uchun `nat`dan 80, `official`dan 89 ID olindi; `official`da qo'shimcha 9 ta ID bor. Bo'limlarni bir-birining teng ekvivalenti deb qabul qilish mumkin emas.

### Pagination va sana segmentlari

Ro'yxat server-rendered HTML, 20 hujjat/sahifa. Raqamli GET `page` parametri taxmin qilinmadi. Saytdagi `href="javascript:__doPostBack(...)"` oqimi takrorlandi:

1. `#Form1` hidden inputlari (`__VIEWSTATE`, `__VIEWSTATEGENERATOR` va mavjud boshqa fieldlar) joriy javobdan olinadi.
2. Shu qidiruv URL'iga POST yuboriladi: `__EVENTTARGET=ucFoundActsControl$LinkButton1`, `__EVENTARGUMENT=''` va hidden fieldlar.
3. Keyingi javobdan hidden state yangilanadi; yangi IDlar yig'iladi. `#ucFoundActsControl_LinkButton1[href]` yo'qolganda oxiriga yetilgan.
4. Repeater sahifa tugmalari ham `__doPostBack` ishlatadi. Alohida cursor API aniqlanmadi.

| Haqiqiy `nat` sana so'rovi (`lang=4`) | Sahifa | Unique ID | Natija |
|---|---:|---:|---|
| `from=01.10.2026&to=28.10.2026` | 4 | 80 | total bilan teng |
| `from=01.10.2026&to=15.10.2026` | 3 | 50 | total bilan teng |
| `from=16.10.2026&to=28.10.2026` | 2 | 36 | total bilan teng |
| `from=23.10.2026&to=23.10.2026` | 1 | 6 | total bilan teng |

Ikki yarim interval kesishmasi **6 ID**, birlashmasi **80 ID**; butun oy natijasi bilan aynan teng. Masalan, hujjatning qabul sanasi va davlat ro'yxatidan o'tkazilgan sanasi boshqa-boshqa bo'lishi mumkin. `8557098`: qabul 06.10, ro'yxat 23.10. Sana filterining bitta qat'iy `issued_at` fieldiga tengligi isbotlanmagan. Segment total'larini qo'shish dublikat hisob beradi; unique set bilan reconciliation kerak.

**Barcha hujjatlar topilishi tasdiqlanmadi.** Eski yillar, barcha tillar, barcha tur/subtoifalar, katta natijalardagi yashirin limit, bir kunning o'zida juda ko'p hujjat bo'lgandagi cap, o'zgarayotgan ro'yxatning snapshot barqarorligi tekshirilmadi. Kichik segmentlarning to'liq aylanishi global 100% qamrovni isbotlamaydi.

## 3. Status, joriy tahrir va tillar

### Status

Listing belgisi `.status_code_y/r/n`; authoritative metadata saytning o'z `showIframeCard()` funksiyasidan topilgan `/uz/actinfo/card1/{id}` orqali olindi. Kartochka `td.lbl` va yonidagi `td` fieldlarini qaytaradi.

- `Действующий` + kelajakdagi umumiy kuchga kirish sanasi → test modelida `pending`. `8557098`: 27.01.2027; `8554315`: 25.01.2027; `8548754`: kelajakdagi sana. Qidiruvdagi `Y` pending'ni ham o'z ichiga oladi.
- `Утративший силу` → `inactive`; `8181977`, `-8102878` bilan tasdiqlandi.
- `N`/`Не действующий` avtomatik pending emas. `-2509996` sahifasida eski qonun yangi tahrirda qabul qilingani ko'rsatilgan, kuchga kirish sanasi esa 2014-yil. Testda inactive sifatida ko'rildi.
- [`-8553692`](https://lex.uz/uz/docs/-8553692): kartochka `Действующий`, sana **23.10.2026**. Render qilingan PDF 1-sahifa, 4-band: butun qarorning kuchga kirishi **01.01.2027**. **Status unresolved**, inson tekshiruvi talab qilinadi. Bitta manba fieldi bilan ishonchli status aniqlash bu holatda o'tmadi. Bu v1 biznes statuslariga yangi qiymat qo'shish qarori emas: P0 testining review holati.

Default HTML URL'lar `ONDATE` parametrsiz olindi. Mehnat kodeksida `.lx_date_selected` — `15.10.2026 01`; oldingi sanalar `ONDATE=...` havolalarida. Eski tahrirlar import qilinmadi. `.COMMENT`, `.COMMENTLEXUZ`, `.CHANGES_ORIGINS`, indekslar va interaktiv UI kontentdan ajratildi. Barcha bo'limlarda future/current tahrirlarning murakkab o'tishi hali tekshirilmagan.

### Mavjud tillar

HTML'da `.docContentHeader__item-link[title]` ichidagi `openUrl('/uz/docs/...')` haqiqiy til munosabatini beradi. Masalan, `8506802` → ruscha `8507863`, inglizcha `8506870`, kirillcha `-8506802`. **ID tildan mustaqil umumiy document ID emas.** `abs(id)` bilan ham barcha tillarni birlashtirib bo'lmaydi.

15 til havolasida to'liq matn o'rniga asl tilga va norasmiy online tarjimaga yo'naltiruvchi xabar qaytdi. Ular `skipped=true`, to'liq tarjima emas; manbada tarjima matni yo'qligi parse xatosi sifatida hisoblanmadi. Haqiqiy English matnlar Suv kodeksi va xalqaro hujjatlarda olindi.

PDF viewer'dagi `.languageSelector` umumiy interfeys menyusi hujjat tillarini bermaydi. `/ru/pdfs/8557098` ruscha UI bilan **o'sha lotincha PDF**ga olib bordi. Haqiqiy `nat?actnum=3962&regnummj=1` so'rovi 2 ID qaytardi: `8557098` va `-8557098`. Ikkala viewer'dan olingan PDF **bir xil SHA-256**ga ega. Bu alohida kirillcha PDF matni mavjudligini isbotlamaydi.

`-8553692` va `-8553258` scanlari amalda **uz-Cyrl**, kartochkada **uz-Latn**. OCR'da `uzb_cyrl+uzb` qo'llandi va natija til labeli manifestda tuzatildi; source label ham saqlandi. Avval faqat `uzb` modeli bilan boshlangan tajriba to'xtatilib, ikkala skan to'g'ri model kombinatsiyasida qayta o'qildi. **Barcha PDF tillarini aniqlash ishonchliligi hali tasdiqlanmadi.**

## 4. HTML, PDF va OCR natijalari

HTML root `#divCont`. `.ACT_TITLE` → `#`, `.TEXT_HEADER_DEFAULT` → `##`, `.CLAUSE_DEFAULT` → `###`; raqamli bandlar manba matni tartibida. Poppler PDF magic `%PDF-` tekshiruvidan keyin ishladi.

`https://lex.uz/uz/docs/8557098` va `/pdfs/8557098` — viewer HTML. Haqiqiy resurs sahifadagi `PDFObject.embed("/pdffile/8557098", ...)`dan topildi. [`/pdffile/8557098`](https://lex.uz/pdffile/8557098) HTTP 200, `application/pdf`, **1 102 002 bayt**, `%PDF-1.4`, **9 sahifa**. Cookie va Authorization headersiz yangi so'rov bilan ham PDF olindi.

PDF extraction barcha 101 sahifani qamradi; sahifa soni `PdfReader` va Poppler form-feed natijasi bilan solishtirildi. 6 PDF text layer orqali o'qildi. 2 tabiiy skan (`-8553692`: 19 sahifa, `-8553258`: 17 sahifa) uchun **36 sahifaning barchasi Tesseract OCR**dan o'tdi. PDFda header/watermark matni borligi butun sahifada matn qatlami borligini anglatmasligi amalda ko'rindi.

| Sifat savoli | Real natija va chegara |
|---|---|
| Markdown matni saqlandimi? | 54/54 to'liq HTML til variantida tozalangan DOM bilan alfanumerik token tartibi teng. Bu filteringdan oldingi barcha matnning yuridik to'liqligini isbotlamaydi. |
| Bob/modda? | Mehnat kodeksida 593 modda sarlavhasi, birinchi/oxirgi qismlar saqlandi; bo'lim/bob sarlavhalari Markdown qilindi. |
| Raqamlar/footnote? | `<sup>` raqamlarining matnga yassilanishi yuridik raqamlash ma'nosini o'zgartirishi mumkin. Shu sabab review flag qo'yildi; mukammal preservation deb ko'rsatilmadi. |
| Band va ilova? | Oddiy matnli band/ilovalar tartibi saqlandi. PDF/OCRda formal bob/modda/ilova Markdown daraxti hali qurilmagan, sahifa chegaralari saqlandi. |
| Jadval? | 14 HTML til variantida jadval mavjud. Oddiy kataklar matni saqlandi; rowspan/colspan yassi Markdown'da semantik bog'lanishni yo'qotishi mumkin. Bunday hujjatlar review. |
| Rasm/chizma? | 4 HTML til variantida matnga aylantirilmagan rasmlar bor. PDFdagi logo, imzo, jadval/chizma farqi avtomatik tasdiqlanmagan; konservativ review. |
| PDF jadvali? | `8557098` 9-sahifadagi shakl jadvali ko'rib chiqildi: `pdftotext -layout` so'z va qatorlarni oladi, Markdown kataklari va bo'sh maydonlar ma'nosini to'liq tiklamaydi. |
| OCR sifati? | Asosiy kirill matni olindi; gerb/muhrlarda shovqin va xato belgilar bor. So'z/belgi xato foizi o'lchanmagan; rasmiy normativ matn sifatida avtomatik qabul qilish tasdiqlanmagan. |
| Matn to'liqmi? | Sahifa/HTML token qamrovi tekshirildi. Vizual element, murakkab jadval, superscript va OCR xatolari sabab barcha hujjatlar uchun to'liqlik kafolati berilmadi. |

Fallback mezoni bu tajribada: sahifada 100 tadan kam text-layer belgi va rasm mavjudligi. Uzun watermark, aralash text/scan, noto'g'ri shrift encodingi, o'qish tartibi va qisman yo'qolgan text layer uchun kengroq validator kerak. OCR budgeti 20 sahifa/hujjat edi; ushbu run'da oshmadi. Katta scanlar uchun buni jim truncation sifatida ishlatish mumkin emas.

## 5. Test import statistikasi

| Ko'rsatkich | Natija |
|---|---:|
| Seed hujjat guruhlari / temporary SQLite satrlari | **30 / 30**, distinct ID 30 |
| Matn extraction bajarilgan seed | **30** |
| Extraction exception bilan tugagan seed | **0** |
| Cheklangan strict quality check o'tgan seed | **11** |
| Review/validation muammosi ochiq seed | **19** |
| HTML seed / PDF seed | **22 / 8** |
| Text-layer PDF / OCR PDF | **6 / 2** |
| PDF sahifalari / OCR sahifalari | **101 / 36** |
| To'liq matn til variantlari | **62** |
| Faqat metadata/xabar bo'lgan til sahifalari | **15**, to'liq matn sifatida olinmadi |
| Haqiqiy matn tillari | **uz-Latn 28, uz-Cyrl 24, ru 6, en 4** |
| Statuslar | **active 23, pending 3, inactive 3, unresolved 1** |
| Sample importga tegishli HTTP request | **115**, 44 964 140 response bayt |
| Import wall-clock | **468.951 s — 7 daqiqa 48.951 soniya** |
| Live source probe oynasi | **759.914 s — 12 daqiqa 39.914 soniya** |

Import: **10:57:12.949–11:05:01.900 Asia/Tashkent**. Bu vaqt OCR modelini tuzatish/to'xtatish/qayta boshlashni ham o'z ichiga oladi. `import_stats.json`dagi 314.878 s faqat resume jarayonining timeri; umumiy import uchun 468.951 s ishlatildi. Live probe oynasi **10:53:16.976–11:05:56.890**; hisobot/offline QA vaqtini o'z ichiga olmaydi. Butun P0 yakun vaqti terminal summary'da alohida qayd qilinadi.

**30/30 extraction ≠ 30/30 sifatli import.** `strict_quality_pass=11` ham to'liq yuridik ekspertiza yoki global source coverage tasdig'i emas. 19 muammoli hujjatni future importer yaxshi ma'lumot ustiga yozmasligi kerak.

### Har bir sample va muammo manzili

Quyidagi jadval barcha 30 seedni, jumladan 19 muammoli URL'ni beradi. Har bir til URL'i va aniq issue code manifestda. Belgilar: **P** PDF struktura/tillar/rasm review; **O** OCR aniqligi; **S** source til va haqiqiy yozuv farqi; **D** kuchga kirish sanasi ziddiyati; **T** murakkab jadval spanlari; **N** superscript/raqamlash; **I** matnga aylantirilmagan rasm. `PASS` faqat yuqoridagi cheklangan validator uchun.

| Seed URL | Status | Format | Sifat / sabab |
|---|---|---|---|
| [8557098](https://lex.uz/uz/docs/8557098) | pending | PDF | P |
| [8554315](https://lex.uz/uz/docs/8554315) | pending | PDF | P |
| [8554432](https://lex.uz/uz/docs/8554432) | active | PDF | P |
| [8554353](https://lex.uz/uz/docs/8554353) | active | PDF | P |
| [8554326](https://lex.uz/uz/docs/8554326) | active | PDF | P |
| [8554321](https://lex.uz/uz/docs/8554321) | active | PDF | P |
| [8554309](https://lex.uz/uz/docs/8554309) | active | HTML | T, N |
| [-8553692](https://lex.uz/uz/docs/-8553692) | unresolved | PDF | P, O, S, D |
| [-8553258](https://lex.uz/uz/docs/-8553258) | active | PDF | P, O, S |
| [8548754](https://lex.uz/uz/docs/8548754) | pending | HTML | I |
| [-7655343](https://lex.uz/uz/docs/-7655343) | active | HTML | T, N |
| [-6257288](https://lex.uz/uz/docs/-6257288) | active | HTML | N |
| [8519448](https://lex.uz/uz/docs/8519448) | active | HTML | N |
| [8498641](https://lex.uz/uz/docs/8498641) | active | HTML | PASS |
| [8496009](https://lex.uz/uz/docs/8496009) | active | HTML | PASS |
| [8506802](https://lex.uz/uz/docs/8506802) | active | HTML | PASS |
| [8502443](https://lex.uz/uz/docs/8502443) | active | HTML | PASS |
| [8501541](https://lex.uz/uz/docs/8501541) | active | HTML | PASS |
| [-8540314](https://lex.uz/uz/docs/-8540314) | active | HTML | T, N, I |
| [8520357](https://lex.uz/uz/docs/8520357) | active | HTML | N |
| [8503180](https://lex.uz/uz/docs/8503180) | active | HTML | PASS |
| [-7613661](https://lex.uz/uz/docs/-7613661) | active | HTML | PASS |
| [-7303292](https://lex.uz/uz/docs/-7303292) | active | HTML | N |
| [-7290944](https://lex.uz/uz/docs/-7290944) | active | HTML | N |
| [8557240](https://lex.uz/uz/docs/8557240) | active | HTML | PASS |
| [8547769](https://lex.uz/uz/docs/8547769) | active | HTML | PASS |
| [8546701](https://lex.uz/uz/docs/8546701) | active | HTML | PASS |
| [8181977](https://lex.uz/uz/docs/8181977) | inactive | HTML | PASS |
| [-8102878](https://lex.uz/uz/docs/-8102878) | inactive | HTML | T, N |
| [-2509996](https://lex.uz/uz/docs/-2509996) | inactive | HTML | N |

## 6. Access, limit, timeout, retry va yangilanishlar

[`robots.txt`](https://lex.uz/robots.txt) 404 HTML qaytardi: mashina uchun crawl-delay/allow qoidasi olinmadi. Bu bulk yuklashga ruxsat degani emas. [Axborotni taqdim etish shartlari](https://lex.uz/uz/axborot) va [foydalanish sahifasi](https://lex.uz/agreement) o'qildi. Birinchisi shaxsiy foydalanish, daromad maqsadidagi nusxalash uchun yozma rozilik va normativ hujjatlar bo'yicha istisnolarni tilga oladi; ikkinchisi amalda maxfiylik siyosati. Barcha hujjat turlarining bulk/mahsulotda foydalanish ruxsati **tasdiqlanmadi**. Bu hisobot huquqiy ruxsat bermaydi.

User guide, statistikalar va offline sahifa ham tekshirildi. Offline dastur uchun litsenziya/yangilash tartibi bor; uning mavjudligi public bulk API degani emas. Tekshirilgan rasmiy sahifalarda hujjatlashtirilgan ochiq bulk API aniqlanmadi; umuman mavjud emasligi da'vo qilinmaydi. Taxminiy API endpointlariga so'rov yuborilmadi.

- Bitta worker va nominal 2 s intervalda real 403/429/5xx, CAPTCHA yoki avtorizatsiya talabiga duch kelinmadi. Maksimal ruxsat etilgan tezlik noma'lum; ataylab blok yoki server overload chaqirilmadi.
- HTML uchun asosiy probe timeout 10/30 s (connect/read), PDF 10/90 s. Sample runner barcha fetchlar uchun 10/90 s ishlatdi; bu agreed production konfiguratsiyasi o'zgartirilgani emas. 3 urinishlik retry chegarasi bor.
- Haqiqiy tasdiqlangan URL'ga ataylab 1 mikrosoniya timeout qo'yilgan urinish **ReadTimeout** berdi; keyingi normal so'rov HTTP 200 bo'ldi. Bu manba nosozligi emas, client fault testi.
- **Lokal fault injection:** 429 + `Retry-After: 2` → 2-urinishda 200; 403 → retry qilinmay to'xtash; read timeout → 2 urinishdan so'ng error. 16 test o'tdi. Real Lex.uz 429/Retry-After holati kuzatilmadi. HTTP-date shaklidagi `Retry-After`, uzoq backoff, keng ko'lamli circuit breaker va distributed limiter hali testlanmagan.
- [`/uz/rss`](https://lex.uz/uz/rss) XML qaytardi, **129 item**; title/guid/link/description/creator/pubDate mavjud. To'liq tahrir va inactive event feed ekanligi tasdiqlanmadi. Ma'lum inactive `8181977` uchun `/uz/actinfo/revhis/8181977` sababchi hujjatlar ro'yxatini berdi, ammo bu per-document oqim. Kunlik RSS barcha repeal/revisionlarni tutadi deb bo'lmaydi; rolling recheck zarurati saqlanadi.

## 7. Full import imkoniyati va asoslangan taxmin

**Texnik jihatdan istiqbolli, hozir full importga NO-GO.** HTTP HTML/PDF olish, oddiy extraction va OCR ishladi. Coverage va quality muammolari jim o'tkazilsa, importer noto'g'ri “completed” berishi mumkin.

Canonical hujjatlar umumiy soni **N noma'lum**. Yuqoridagi totals turli segment/til birliklari, bo'limlar kesishishi mumkin; ularni qo'shib “barcha active hujjatlar soni” deb ko'rsatish mumkin emas. `unique=58 018` ham ishonchli active count emas.

Namuna: 30 seed uchun 115 request = **3.83 request/seed** (discovery/qo'shimcha diagnostika kirmagan). Tillar listingi, sana segmentlari, PDF tilini izlash va qayta urinishlar bilan rejalash uchun **4–6 request/canonical hujjat** ssenariysi ishlatilishi mumkin; bu o'lchangan global o'rtacha emas.

| Faraz qilingan N | Request ssenariysi | 2 s intervaldagi faqat tarmoq minimumi | Namunaning 15.63 s/seed wall-clock tezligini chiziqli qo'llash |
|---|---:|---:|---:|
| 20 000 | 80 000–120 000 | 1.85–2.78 kun | taxminan 3.62 kun |
| 50 000 | 200 000–300 000 | 4.63–6.94 kun | taxminan 9.05 kun |
| 100 000 | 400 000–600 000 | 9.26–13.89 kun | taxminan 18.09 kun |

Bu **ssenariylar**, real full import natijasi yoki kafolat emas. Sample tasodifiy emas; 2 scan, katta kodekslar, metadata-only tillar va OCR qayta bajarilishi tezlikka ta'sir qilgan. Katta scanlar ulushi, source o'zgarishi, rate limit, sahifa caplari, parser CPU/ram va uzun retrylar muddatni oshirishi mumkin. OCRning alohida per-page timeri yozilmagan; mustaqil OCR throughput taxmini berilmaydi. Keyinchalik 1000 hujjatlik sinov o'tkazilishi kerak, hozir bajarilmadi.

## 8. Implementatsiyadan oldingi takliflar — tasdiqlanmagan

`LEX_SCRAPING_PLAN.md`dagi 12 qaror, 4 biznes jadval, Database Queue, Laravel/PHP versiyalari, Markdown, OCR va conservative worker modeli o'zgartirilmadi. Quyidagilar PLAN'ga kiritish uchun **taklif**, avtomatik qabul qilingan qarorlar emas:

1. **Discovery kontrakti:** GET filterlar + stateful POST pagination; segment checkpointida filter URL, yakunlangan page holati va unique reconciliation. Session/viewstate muddati o'tganda segmentni xavfsiz qayta boshlash; maxsus GET page API'ni taxmin qilmaslik.
2. **Canonical identity:** hujjat guruhini source til havolalari grafigi bilan aniqlash. `documents.external_id` canonical tanlash siyosati kerak; til bo'yicha external ID/URL mappingni mavjud `metadata` JSONB'da saqlash mumkin. Yangi jadval/ustun hozir qo'shilmadi. Listing countning til-record yoki canonical birlik ekanligi ajratilsin.
3. **Pending/status:** `Y` kelajakdagi hujjatlarni ham qamraydi; `N` pending emas. Kartochka va normativ matn ziddiyatida importni quarantine/failed validation sifatida ushlash. V1 biznes statuslariga `unresolved` qo'shish taklif qilinmayapti; parse/quality natijasida review sababi saqlanishi kerak.
4. **Tillar:** to'liq matn, faqat metadata tarjimasi, interfeys tili va PDF aliasi ajratilsin. Source label fizik yozuvdan farq qilishi mumkin. Faqat haqiqatan olingan matn tegishli BCP47 label bilan yozilsin, avtomatik tarjima qilinmasin.
5. **Sifat:** superscript raqamlash, murakkab table spanlari, rasmli ilova, page-level OCR va PDF strukturasiga aniq acceptance threshold. Minimal belgilar soni bilan “valid” deyish yetarli emas. OCR/rasm/jadval review kerak bo'lsa strict completion berilmasin; avvalgi yaxshi kontent saqlansin.
6. **Operatsiya:** discovery/HTML/PDF/til lookup/retry uchun yagona limiter; monotonic interval dalili, HTML va PDF timeoutlarini ajratish, Retry-After HTTP-date va uzun deferral, OCR/job budget, vaqtinchalik fayllarning har qanday xatoda cleanup'i. Shu kichik spike PHP/Laravel queue timeoutlarini isbotlamaydi.
7. **Coverage gate:** barcha tillar va tarixiy sana diapazonlarida count reconciliation, juda katta segment/cap, sud subtoifalari, listing o'zgarishidagi restart/resume tekshirilsin. Tasdiqlangan 30 seed qamrovi global completed bo'lmasin.
8. **Access va sync:** boshqa hujjat turlari/bulk foydalanish doirasini aniqlashtirish; rasmiy bulk/API imkonini manba egasidan aniqlash mumkin. Hech kimga xabar yuborilmadi. RSS universal delta deb qabul qilinmasin; 90 kunlik recheck qarori saqlanadi.

## 9. Yakuniy gate va keyingi qadam

**Muvaffaqiyatli:** real discovery yo'llari, kichik segmentlarda POST pagination/count, HTML/PDF resurslari, 30 seed extraction, 4 haqiqiy matn tili, 2 tabiiy scan/36 sahifa OCR, katta HTML va metadata-only tarjimani aniqlash, lokal retry/timeout/403/429 testlari.

**O'tmagan yoki tasdiqlanmagan:** barcha hujjat/tillarni global topish; PDF source-language discovery; kartochka/PDF status ziddiyatini umumiy yechish; murakkab jadval, superscript, rasm va OCRning normativ aniqligi; bulk ruxsat va haqiqiy server rate limiti; to'liq revision/inactive delta-feed. Bu masalalar o'lchangan muvaffaqiyat sifatida ko'rsatilmadi.

**P1 hozir tavsiya etilmaydi.** Tavsiya: avval ochiq P0 masalalari bo'yicha qaror va tor qayta tekshiruv, so'ng foydalanuvchining **aniq tasdig'i** bilan P1. P1/P2 yoki full import avtomatik boshlanmaydi. Foydalanuvchi qarori kutiladi.

**Yakuniy audit va tozalash:** 2026-10-28 11:15:48 +05. Birinchi live HTTPdan yakuniy hisobot, cleanup va offline qayta testgacha o'lchangan P0: **1351.821 soniya (22.53 daqiqa)**; dastlabki hujjat o'qish/setup bu timerga kirmaydi. 182 ta vaqtinchalik raw/DB fayl (70268575 bayt) o'chirildi; [cleanup dalili](p0/cleanup.json). Raw fayllarsiz offline **16/16 test qayta o'tdi**. P1/P2 boshlanmagan.
