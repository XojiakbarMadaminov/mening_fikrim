# P0.1 — Critical Issues Resolution

**Sana:** 2026-10-28, Asia/Tashkent. **P1: CONDITIONAL GO. Full import: NO-GO.** Bu implementatsiyani avtomatik boshlashga ruxsat emas; foydalanuvchi tasdig'i kutiladi.

## A. Executive Summary

P0 dagi ishlagan HTTP, listing, metadata va source-cleaning mexanizmlari qayta yozilmadi. Ularning ustida bounded live probe, canonical identity, loss-aware formatting va atomik admission kontraktlari tekshirildi. Laravel loyihasi yaratilmadi, production PostgreSQL'ga ulanish va full import bajarilmadi. PLAN'ning tasdiqlangan qarorlari o'zgarmadi.

**Hal qilingan qismlar:** til variantlarining isbotlangan graph'i; takroriy kelishda bitta ichki hujjatni saqlash modeli; numeric superscript; source sana ziddiyatini IDga xos istisnosiz aniqlash; quality/status/missing-language/shrinkage holatlarida eski snapshotni saqlash; real checkpoint replay. Discovery'dagi `lang=4` cheklovining qamrov xatosi aniqlandi va undan voz kechadigan strategiya tayyorlandi.

**Qolgan qismlar:** global corpus coverage, PDF til aliaslari, murakkab jadval/rasm/PDF struktura va OCRning to'liq normativ aniqligi; haqiqiy token expiry muddati; PostgreSQL JSONB/lock/Queue integration; bulk foydalanish doirasi. Bu holatlar muvaffaqiyatli full import deb hisoblanmadi.

| O'lchov | Real natija |
|---|---:|
| Yozib olingan Lex.uz HTTP request | **139**, barchasi HTTP 200 |
| Eng kichik monotonic start oralig'i | **2.050024 s**, bitta worker |
| Response hajmi | **52 970 120 bayt** |
| Identity pilot | **4 guruh, 15 source ID, 42 yo'nalgan tasdiqlangan aloqa** |
| Qayta tekshirilgan eski review seedlar | **19**: 11 HTML, 8 PDF |
| Qayta tekshirilgan to'liq HTML variantlari | **28**, yana 7 metadata-only sahifa |
| PDF / OCR qamrovi | **101 / 36 sahifa**, 2 tabiiy skan |
| 19 review ichidan yangi pass / qolgan review | **6 / 13** |
| Yangi offline/model testlari | **37/37** |
| Oldingi regressiya testlari | **16/16**, localhost fault injection bilan |

**Muhim taqqoslash:** oldingi 11 pass hujjat qayta to'liq parse qilinmadi. `11 old pass + 6 new pass = 17/30` — carry-forward bilan kompozit hisob; **30 tasining barchasi shu run'da qayta o'tdi degani emas**. Joriy real retest natijasi 19 dan 6 pass, 13 review.

Live so'rov oynasi **11:33:30.373–12:03:00.649 Asia/Tashkent**; u orasidagi kodlash/tekshiruv pauzalarini ham o'z ichiga oladi. 36 sahifa uchun render + OCR timerlari yig'indisi **130.996 s**; bu to'liq import throughputi emas. Yakuniy P0.1 audit davomiyligi terminal summary'da qayd qilinadi.

Dalillar: [stats](p0_1/stats.json), [HTTP log](p0_1/requests.jsonl), [monotonic log](p0_1/timing.jsonl), [37 test](p0_1/test_output.txt), [16 regressiya](p0_1/p0_regression_output.txt), [qaytarish yo'riqnomasi](p0_1/README.md). Taxmin va lokal fault testlari haqiqiy Lex.uz hodisasi sifatida ko'rsatilmadi.

## B. Canonical Identity

### Tekshirilgan algoritm

1. Hujjatning haqiqiy `.docContentHeader__item-link` til kontrollaridagi `openUrl(...)` manzillari olinadi. Global interfeys tili menyusi bu graph emas.
2. Har bir uchdagi sahifa va source'dan topilgan `card1` rekvizitlari olinadi. Faqat **o'zaro til havolasi** bor edge qabul qilinadi.
3. Hujjat turi/formasi va qabul qiluvchi organ–sana–raqam rekvizitlari mos bo'lishi kerak. Ikkala kartochkada mavjud registration/publication fieldlari zid bo'lsa review. Tarjimada publication fieldining bo'shligi ziddiyat emas: aynan `8554309` va Suv kodeksida uchradi.
4. Graph'da missing node, bir tomonlama aloqa yoki haqiqiy requisites conflict bo'lsa, butun candidate review; taxmin bilan bo'laklarini alohida saqlash yo'q. Bir xil title/hash yoki `abs(id)` merge dalili emas.
5. Yangi yopiq verified component uchun tajribada opaque source IDlarning lexical minimumi boshlang'ich anchor bo'ldi. `8506802` guruhi uchun bu `-8506802`; bu “asl tildagi ID” degan da'vo emas. **Persist qilingan anchor keyin o'zgarmaydi**, asosiy universal ID — `documents.id`.
6. Barcha kiruvchi verified aliaslar source doirasida mavjud metadata aliaslari bilan tekshiriladi. Bitta row topilsa o'sha ichki ID ishlatiladi; ikkita row topilsa avtomatik merge qilinmaydi. Yangi component tasdiqlanmaguncha insert yo'q.

| Boshlang'ich seed | Bog'langan source IDlar | O'zaro edge |
|---|---|---:|
| `8506802` | `-8506802`, `8506802`, `8506870`, `8507863` | 12 |
| `8554309` | `-8554309`, `8554309`, `8555578`, `8557123` | 12 |
| `-7655343` | `-7655343`, `7655343`, `7658581`, `7658590` | 12 |
| `-8540314` | `-8540314`, `8540314`, `8543270` | 6 |

4/4 pilot guruh rekvizitlari bilan yarashdi. Bu butun corpus uchun aniqlik foizi emas. Metadata-only RU/EN sahifalari identity node bo'lishi mumkin, ammo full-text content sifatida saqlanmaydi. PDFda teng bayt/hash faqat resource alias signalidir; global language menu yoki teng hash o'zi canonical merge uchun yetarli emas. PDF guruhlarini umumiy aniqlash hali review talab qiladi.

### Dublikat va JSONB modeli

Vaqtinchalik **4 jadvalli SQLite** modelida bitta verified guruhning 4 entrypointi **24 kelish tartibida, 96 chaqiruv** bilan ishlatildi: **created 1, unchanged 95; documents 1, contents 3**. RU sahifa metadata-only bo'lgani uchun 4-content yaratilmagan. Boshqa source namespace bir xil IDlardan mustaqil ichki document yaratdi. Persist qilingan anchor, missing language, noto'g'ri entry, quality review va transaction o'rtasidagi shrinkage rollback ham testlandi.

Aliaslar va `language -> external_id/url/availability` mappingi mavjud `documents.metadata` JSONB'ga sig'adi. [Aniq metadata namunasi va audit](p0_1/store_audit.json). Universal source/manual model o'zgarmaydi; Lex mapping faqat Lex adapterining metadata qismi.

**Chegara:** model JSON TEXT ishlatdi. PostgreSQL JSONB query, indekslar yoki locklar real testlanmadi. `UNIQUE(source_id,external_id)` JSON ichidagi har bir aliasning DB darajasidagi unikalligini bermaydi. Yangi jadvalsiz strategiya: barcha writerlar uchun source lock + alias lookup + bitta transaction; P1–P5'da PostgreSQL race/rollback testi majburiy. JSONBda bir nechta row'ga urilish manual review bo'ladi. GIN yoki qo'shimcha jadval hozir qo'shilmadi.

Dalillar: [graphlar](p0_1/identity_graphs.json), [edge qarorlari](p0_1/identity_results.json), [model](p0_1/store_audit.json).

## C. Discovery Coverage

### Bo'limlar va til filteridagi tuzatish

`nat` — milliy hujjatlar, `loc` — mahalliy, `int` — xalqaro, `tech` — texnik, `court` — sud, `oliy` — Oliy Majlis guruhlari. UI'dagi bo'limlar, haqiqiy so'rovlar va namunalar bilan bu rollar tekshirildi. `official` rasmiy e'lonlar cross-check/daily signal; `unique` esa status va sana filterlari ishonchli ishlashi tasdiqlanmagan umumiy yordamchi ro'yxat.

| `status=Y`, til filterisiz root | Source total — raw listing record |
|---|---:|
| nat | 55 971 |
| loc | 22 307 |
| int | 4 997 |
| tech | 3 221 |
| court | 1 613 |
| oliy | 30 900 |

Bu sonlar canonical hujjatlar emas; variantlar va bo'limlar kesishishi bor. Yig'indisi “barcha hujjatlar” soni sifatida ishlatilmadi. Har birida faqat birinchi sahifa ko'rildi.

`court_form=F/H/J` haqiqiy UI optionlaridan olindi. `lang=4&status=Y` bilan uchalasi **0**; til cheklovisiz `status=Y` bilan **4 / 856 / 1**. F ni statussiz qidirish ham 4 berdi. [`2831120`](https://lex.uz/uz/docs/2831120) kartochkasida `Русский`, `Действующий`, sahifada RU/Cyrl/Latn manzillari taklif qilingan. Bu manzillar taklif etilgani ularning barchasida full text borligini o'zi tasdiqlamaydi. **Lotin filteri nol chiqishi source'da shu guruh yo'q degani emas.**

Tavsiya: primary discovery `nat/loc/int/tech/court/oliy` bo'yicha **tilni cheklamasdan Y** nomzodlarini yig'adi; keyin source/card/text status validation va til graph'i. Sud subtoifalari ham coverage registry'da bo'lsin. `N` pending sifatida yig'ilmaydi; noma'lum holatlar alohida tekshiriladi. `official` qo'shimcha signal, asosiy archive o'rnini bosmaydi. `unique` filtriga tayanilmaydi.

### Tarixiy va pagination sinovlari

| Test | Source total | Sahifa | Unique ID | Takror | Reconciliation |
|---|---:|---:|---:|---:|---|
| nat_oct | 80 | 1 | 20 | 0 | budget/partial |
| loc_oct | 0 | 1 | 0 | 0 | to‘liq |
| int_oct | 0 | 1 | 0 | 0 | to‘liq |
| tech_oct | 0 | 1 | 0 | 0 | to‘liq |
| court_oct | 0 | 1 | 0 | 0 | to‘liq |
| oliy_oct | 4 | 1 | 4 | 0 | to‘liq |
| official_oct | 89 | 1 | 20 | 0 | budget/partial |
| unique_oct | 58018 | 1 | 20 | 0 | budget/partial |
| nat_1992 | 11 | 1 | 11 | 0 | to‘liq |
| nat_2000 | 11 | 1 | 11 | 0 | to‘liq |
| nat_2010 | 22 | 2 | 22 | 0 | to‘liq |
| nat_2020 | 89 | 3 | 60 | 0 | budget/partial |
| loc_2010 | 3 | 1 | 3 | 0 | to‘liq |
| int_2000 | 44 | 3 | 44 | 0 | to‘liq |
| tech_2020 | 10 | 1 | 10 | 0 | to‘liq |
| court_2010 | 84 | 3 | 60 | 0 | budget/partial |
| oliy_2000 | 14 | 1 | 14 | 0 | to‘liq |

Bu 17 asosiy testdan **12 tasi to'liq reconciliation** qildi; 5 tasi budget bilan cheklangan yoki katta ro'yxatning faqat boshlanishi. Qo'shimcha til/sud filterlari va tarixiy split alohida dalillarda. Source explicit “hujjat topilmadi” xabari 0 sifatida qabul qilindi; shunchaki noto'g'ri/bo'sh HTML hech qachon 0 emas.

2010-yil yanvar `nat`: 22 unique ID. 1–15 va 16–31 intervallari birlashmasi aynan 22, kesishmasi 0. P0'dagi 2026-yil oktabr 50+36 intervalida esa **6 dublikat** bor edi; o'sha 80 ID hozirgi real resume bilan ham tasdiqlandi. Interval total'lari qo'shilmaydi: source ID set, keyin verified canonical component set ishlatiladi.

`nat/official/unique` birinchi 20 IDsi bir xil chiqdi; bu to'liq set tengligi emas. To'liq may-2000 `nat` va `oliy` o'rtasida, yanvar-2010 `nat` va `loc` o'rtasida 0 ID kesishma kuzatildi. Barcha bo'limlarning kesishmasi universal ravishda 0 degan xulosa qilinmadi.

ASP.NET oqimi yana real POST bilan o'tdi: joriy `#Form1` hidden state + source'dagi `__doPostBack` target. Katta `nat lang=4 Y`: **15 787**, ikkinchi sahifa 21–40, oxirgi sahifa **790**, 15781–15787. Til filterisiz `nat Y`: **55 971**, oxirgi sahifa **2799**, 55961–55971. Shunday qilib ushbu query'da 1000 sahifadan keyingi last-page o'tishi ham ishladi. **O'rta sahifalar to'liq aylanilmadi; barcha query/corpus uchun cap yo'q degan isbot emas.**

### Checkpoint va stale state

Real kichik oyda 1-sahifaning 20 IDsi checkpointga yozildi. Eski Viewstate bilan yangi cookie jar'da 2-sahifa olindi, ammo uni committed deb yozmasdan fresh GET'dan replay qilindi: **80 unique, 20 dublikat skip, total 80, oxirigacha**. Bu uzilishdan keyingi idempotent replay sinovi; haqiqiy Laravel dispatch/Queue atomikligi hali testlanmagan.

Til filterisiz last-page o'tishida taxminan **425 s oldingi state yangi session'da ishladi**. Haqiqiy expiry yuz bermadi; uning muddati noma'lum. Invalid/cycling/total-mismatch javoblar uchun lokal fault testi checkpointni oldinga surmaslikni ko'rsatdi. Durable checkpoint filter/interval va committed ID/dispatch proofiga tayanishi kerak; Viewstate transient. Expiry'da fresh GET/replay/dedup, takroriy muvaffaqiyatsizlikda failed/incomplete — sahifani skip qilib completed emas.

**Global qamrov tasdiqlanmadi.** Barcha tarixiy kunlar, toifalar va sudning barcha branch/type optionlari, o'rta sahifalar, source o'zgarishidagi snapshot drift hamda nol natijalarni kengroq cross-check hali ochiq. Source total/unique yarashuvi faqat sinov segmenti uchun dalil.

Dalillar: [discovery](p0_1/discovery.json), [barcha tillar](p0_1/all_language_discovery.json), [sud](p0_1/court_subtypes.json), [gap misoli](p0_1/court_language_gap_example.json), [split](p0_1/historical_split.json), [overlap](p0_1/overlaps.json), [resume](p0_1/resume.json), [katta sahifalar](p0_1/large_pagination.json), [2799-sahifa](p0_1/large_all_language_last.json).

## D. Status Validation

Biznes statuslar o'zgarmadi: **active/pending/inactive**. `review`, `valid`, `business_status=null` — saqlashdan oldingi validation natijasi, yangi biznes status emas.

- `Действующий` + ishonchli yagona future effective date → pending; o'tgan/bugungi sana → active. `Y` o'zi active emas. `8557098`, `8554315`, `8548754` future holatlari tekshirildi.
- `Утративший силу` → inactive; `8181977`, `-8102878` real metadata. `N/Не действующий` pending emas; `-2509996` eski tahrir konteksti bilan tekshirildi. N labeli kelajakdagi yoki yo'q effective date bilan kelsa inactive ham taxmin qilinmaydi — review; bu guard nazoratli testda sinaldi.
- Yangi real label `Amalda` (`8554326`) uchradi. Listingdagi **shu IDning Y belgisi** va effective date bilan corroboration bo'lsa active; corroboration yoki label mappingi yo'q bo'lsa review. Noma'lum label “taxminan active” qilinmaydi. Listing/card mos kelmasa review.
- Butun hujjatga tegishli cheklangan lexical pattern (`Ушбу қарор ... кучга киради`, `Ushbu qaror ... kuchga kiradi`) metadata sanasi bilan solishtiriladi. Alohida band/qismning kelajakdagi sanasi butun hujjatni avtomatik pending qilmaydi. Bu umumiy yuridik NLP parseri emas; qamralmagan ifodalar riski qoladi.
- `-8553692`: card **23.10.2026**, PDF 1-sahifa 4-band **01.01.2027**. Ziddiyat **ID uchun hardcoded istisnosiz** matndan aniqlandi. Source ziddiyatining o'zi hal bo'lmadi; candidate review, oldingi yaxshi yozuvga tegilmaydi.
- OCRga tayangan status dalili to'liq tasdiqlanmagan bo'lsa review; yuqori confidence o'zi huquqiy sana/status sertifikati emas. Missing yoki bir nechta umumiy effective date ham review.

Yangi 19 seed ichida status validation natijasi: 12 active, 3 pending, 2 inactive; 2 OCR hujjat review (`business_status=null`). Qo'shimcha refetch qilingan `8181977` inactive holatini tasdiqladi. Bu vaqt o'tishidagi real lifecycle kuzatuvi emas; vaqt/sana va noto'g'ri candidate holatlari nazoratli testlarda ham tekshirildi.

Modelda validation review, missing language, quality issue yoki katta qisqarish bo'lsa **status, metadata va barcha contentlar avvalgi snapshot bilan teng qoldi**; transaction o'rtasidagi xatoda ham rollback. Production'da `last_checked_at` faqat to'liq muvaffaqiyatda o'zgarishi kerak; Laravel/PostgreSQL testi keyingi bosqichda.

## E. Parsing Quality

### HTML

Numeric superscript Unicode bilan saqlandi (`¹`, `²`, ...), bo'sh `<sup>` chiqarildi. Hujjatning rasmiy raqami oddiy raqamlarga birlashtirilmadi. Nonnumeric/matematik superscript (`max`, `-0,67`, uzun izoh) `^{...}` sifatida ochiq saqlanib review bo'ladi; semantikasi tasdiqlanmagan matn avvalgi yaxshisini almashtirmaydi.

Murakkab jadval kataklari qator/ustun koordinatasi va `rowspan/colspan` bilan Markdown inventariga olindi. Barcha tekshirilgan katak matnlari retained. Overlap/out-of-bounds/nested-table holatlari alohida guard. Lekin jadval headerlarining ma'noli bog'lanishi avtomatik isbotlanmagan; complex table review qoladi. Jadvalni yassi matnga aylantirib “to'liq” deb belgilash yo'q. Rasmlar jadval transformatsiyasidan oldin ham hisoblanadi va jim yo'qolmaydi.

| Sample URL | P0 | P0.1 real retest | Qolgan masala |
|---|---|---|---|
| [8557098](https://lex.uz/uz/docs/8557098) | review | review | PDF struktura |
| [8554315](https://lex.uz/uz/docs/8554315) | review | review | PDF struktura |
| [8554432](https://lex.uz/uz/docs/8554432) | review | review | PDF struktura |
| [8554353](https://lex.uz/uz/docs/8554353) | review | review | PDF struktura |
| [8554326](https://lex.uz/uz/docs/8554326) | review | review | PDF struktura |
| [8554321](https://lex.uz/uz/docs/8554321) | review | review | PDF struktura |
| [8554309](https://lex.uz/uz/docs/8554309) | review | review | murakkab jadval |
| [-8553692](https://lex.uz/uz/docs/-8553692) | review | review | PDF struktura, OCR accuracy, sana ziddiyati |
| [-8553258](https://lex.uz/uz/docs/-8553258) | review | review | PDF struktura, OCR accuracy |
| [8548754](https://lex.uz/uz/docs/8548754) | review | review | rasm |
| [-7655343](https://lex.uz/uz/docs/-7655343) | review | review | murakkab jadval |
| [-6257288](https://lex.uz/uz/docs/-6257288) | review | PASS | numeric sup tuzatildi |
| [8519448](https://lex.uz/uz/docs/8519448) | review | PASS | numeric sup tuzatildi |
| [-8540314](https://lex.uz/uz/docs/-8540314) | review | review | murakkab jadval, rasm, matematik/izoh sup |
| [8520357](https://lex.uz/uz/docs/8520357) | review | PASS | numeric sup tuzatildi |
| [-7303292](https://lex.uz/uz/docs/-7303292) | review | PASS | numeric sup tuzatildi |
| [-7290944](https://lex.uz/uz/docs/-7290944) | review | PASS | numeric sup tuzatildi |
| [-8102878](https://lex.uz/uz/docs/-8102878) | review | review | murakkab jadval, matematik/izoh sup |
| [-2509996](https://lex.uz/uz/docs/-2509996) | review | PASS | numeric sup tuzatildi |

6 yangi passning barcha source'da taklif etilgan full-text HTML variantlari qayta tekshirildi; metadata-only variantlar full text emas. Final katta HTML variant parse timeri maksimumi **2.906 s** bo'ldi; bu Python pilotidagi lokal o'lchov, Laravel performance va'dasi emas.

### PDF, jadval va sahifa chegaralari

8 PDFning 101 sahifasi Poppler form-feed va PDF page count bilan yarashdi. Barcha sahifalarda image rectangle coverage, body text belgilar soni va vertikal text coverage tekshirildi. Bob/modda/ilova uchun tor heading patternlari sinab ko'rildi; to'liq huquqiy daraxt isbotlanmaganligi sabab `pdf_structure_unverified` saqlandi.

`8557098` 9-sahifadagi ko'z bilan tekshirilgan jadval **9×5**. Pdfplumber default/3/5 tolerance bilan **11×11**, lines + tolerance 8 bilan **9×5**, `lines_strict` bilan esa jadval topilmadi. Bu aniq yaxshilanish, lekin bitta fixturega moslangan sozlama: universal parser qarori sifatida qabul qilinmadi. Kataklar/shapes borligi o'qish tartibi va bo'sh maydon semantikasini kafolatlamaydi. Ayrim font descriptorlarda `FontBBox` warningi bo'ldi; extraction tugadi, bu geometry sifatini avtomatik tasdiqlamaydi.

### Partial text layer va OCR

19+17 sahifali tabiiy scanlarda header/watermark matni bor, asosiy matn esa rasm. Yangi page guard faqat umumiy 100 belgi bilan cheklanmaydi: image coverage >60% va body chars<100 **yoki** text vertical coverage<35% bo'lsa OCR; katta image bilan boshqa text layer bo'lsa ham spotcheck/review. 450 belgili, faqat yuqorida joylashgan layer holati nazoratli geometry regression testi bilan tekshirildi — qo'shimcha real Lex PDF sifatida ko'rsatilmadi.

36 sahifa qayta OCR qilindi, `uzb_cyrl+uzb` modeli va TSV word confidence olindi. Physical yozuv uz-Cyrl; metadata labeli uz-Latn bo'lishi mumkin. Mean page word confidence diapazoni **81.69–96.05**. 2 sahifa pilot confidence thresholdiga tushmadi: mean<90 yoki confidence<70 so'z ulushi>5%.

Vizual renderdan qo'lda transkripsiya qilingan kichik gold bandlar:

| Source / sahifa | Normalizatsiya qilingan reference | Xato / CER |
|---|---:|---:|
| `-8553692`, 1-sahifa, 4-band | 52 belgi | 0 / 0% |
| `-8553258`, 17-sahifa, 39-band | 105 belgi | 2 / 1.9048% |

Ikkinchisida “талабларининг” so'zining oxiri yo'qolgan. Punctuation/case/whitespace normalizatsiya qilingan; bu ikki juda kichik excerpt, butun 36 sahifa CER'i emas. Confidence haqiqiy xato foizi emas. **Ikkala OCR hujjat ham full/strict muvaffaqiyatli import deb qabul qilinmadi.**

### Aniq admission qoidalari

| Muammo | Saqlashdan oldingi qaror |
|---|---|
| Rasmiy numeric superscript yo'qolsa yoki noto'g'ri biriksa | reject/review; Unicode raqamlash va regression fixture tekshiruvi |
| Nonnumeric sup, table geometry/header ambiguity | review; katak/izohlar ochiq saqlansin |
| Rasm/chizma semantik matni olinmasa | review; jim tashlab ketilmasin |
| PDF page count mos kelmasa | reject; partial extraction saqlanmasin |
| PDF struktura, table reading order isbotlanmasa | review |
| Partial/image-backed text yoki buzilgan layer gumoni | page OCR/spotcheck; hali shubhali bo'lsa review |
| OCR confidence past | review; yuqori confidence ham manual/document-level mezonsiz auto-accept emas |
| Script/declared language farqi | physical yozuv aniqlansin; ambiguity yoki alias coverage ochiq bo'lsa review |
| Manbada bor full text tili olinmasa | atomik candidate reject; source metadata-only esa skip |
| Status/date conflict, noma'lum label | review; eski biznes status/contentni saqlash |
| Oldingi kontentdan >30% qisqarish | pilot review guard; optimal global threshold emas |

P0.1 Python/pdfplumber tajribasi kelajakdagi Laravel parseri to'liq tayyorligini anglatmaydi. Production parser ushbu fixturelar va himoya kontraktlariga qayta testlanadi.

## F. Database va Architecture

**4 biznes jadval bu bosqich uchun yetarli ko'rinadi; yangi jadval/ustun qo'shilmadi.** Alias, til mappingi va proof provenance uchun mavjud JSONB; current text uchun mavjud `document_contents` va SHA-256 yetadi. `documents.id` barqaror universal ID bo'lib qoladi; boshqa source va manual hujjatga Lex ID qoidasi majburan qo'llanmaydi.

Taklif etilgan portable import inputida `source_id`, verified source aliaslar va tayyor validation natijasi bo'ladi. Lex selectorlari/requisites tekshiruvi adapterda, DB lookup esa source slug namespace'i (`metadata[source_slug].identity`) orqali umumiy importerda qoladi. Source bo'yicha DB lookup adapterga ko'chirilmaydi. Temporary modeldagi `other` testi shu generic namespace'ni ham tekshirdi; boshqa real providerga ulanish bajarilmadi.

1 worker va mavjud framework source lock bilan alias lookup + transaction protokoli tavsiya etiladi. Barcha writerlar bu protokolni chetlab o'tsa, JSONB alias unikalligi buzilishi mumkin; SQLite modeli buni PostgreSQL concurrency darajasida isbotlamadi. Juda katta corpusda JSONB alias qidiruvi sekinlashishi mumkin; yangi GIN/jadval qarori faqat real query profiling va foydalanuvchi tasdig'i bilan.

Review biznes enumga qo'shilmaydi. Ishonchsiz candidate eski documentni o'zgartirmaydi; sabab/run/source ID framework `failed_jobs` payloadi va run diagnostikasida ko'rinishi mumkin. Cheklanmagan review ro'yxatini bitta katta checkpoint JSON'ga to'ldirish tavsiya etilmaydi. Hech qanday framework migration yoki queue implementatsiyasi hozir bajarilmadi.

Tasdiqlashga chiqarilgan aniq takliflar: [P0_1_PLAN_PROPOSALS.md](P0_1_PLAN_PROPOSALS.md). `LEX_SCRAPING_PLAN.md` SHA-256 avvalgidek: `bc4bcfdff125dbc8ea54130c28af575601ec2f4891707732d971b56e696d79cf`.

## G. Remaining Risks

- Barcha toifa/davr/tillar bo'yicha global coverage va snapshot drift tasdiqlanmagan. Root va sud subtoifalari kesishishi to'liq aylanilmagan; source totals canonical total emas.
- PDF aliaslari/global UI language menyusi full-text language discovery uchun yetarli emas. Bir tildagi ikki zid source matn va yetishmayotgan variant avtomatik tanlanmaydi.
- Identity pilot 4 guruh; corpusdagi barcha one-way/missing/malformed relation holatlari noma'lum. Collision bo'lsa P1 importer fail-closed bo'lishi kerak.
- `-8553692` source effective-date ziddiyati qoldi. Lexical status pattern barcha huquqiy iboralarni qamramaydi; OCR status evidence o'zi ishonchli emas.
- 13 sample review qoldi. Barcha OCRni avtomatik review qilish full importni ko'p manual ish bilan cheklashi mumkin; document-level OCR acceptance mezoni va tekshirish jarayoni kelishilishi kerak.
- PostgreSQL JSONB/lock/rollback, Laravel Queue dispatch/checkpoint atomicligi va 1000 hujjat stress/integration ishlamagan. 96 SQLite upsert bunga ekvivalent emas.
- Haqiqiy Viewstate expiry, real Lex.uz 429/5xx/Retry-After va maksimal ruxsat etilgan tezlik kuzatilmadi. 139 requestning 200 bo'lishi serverning umumiy limitini yoki bulk ruxsatni bermaydi. Blokni chetlab o'tish bajarilmadi.
- [Axborot shartlari](https://lex.uz/uz/axborot) shaxsiy/tijorat foydalanish va normativ hujjatlar istisnolarini ajratadi; barcha toifalarning ommaviy mahsulotda ishlatilish doirasi tasdiqlanmagan. P0'dagi robots 404 va public bulk API/RSS cheklovlari yopilgani da'vo qilinmaydi. Hech kimga murojaat yuborilmadi.
- Parsed Markdown source DOM'dan farqli representatsiya; token/cell retention yuridik to'liqlik sertifikati emas. Asl HTML/PDF doimiy saqlanmaganligi keyingi auditda qayta fetch yoki tasdiqlangan kichik fixture/hashga tayanishni talab qiladi.

## H. Final Recommendation

**P1 — CONDITIONAL GO.** Laravel/PHP/PostgreSQL loyihasini keyin yaratish uchun yetarli texnik yo'l va xavfsiz kontrakt bor. Bu oldingi P0 NO-GO xulosasidan farqi: P1 foundation va full import gate'lari endi aniq ajratildi; muammoli natijalarni qabul qilmaslik ham real testlandi. P1da adapter/validator noaniq holatni fail-closed ushlashi va ushbu regression fixturelarga mos yozilishi shart. Arxitektura takliflari foydalanuvchi ko'rib chiqishiga chiqarilgan.

**Full import — NO-GO.** Quyidagilar yopilmaguncha boshlanmasin:

1. Toifa/til/davr registry'si, bounded segment cap va source-total/unique reconciliation; scope drift va resume dalili.
2. Source aliaslari uchun PostgreSQL lock/unique lookup va bir vaqtdagi daily/full dispatch testlari; duplicate/collision yo'q.
3. Har candidate uchun credible status va barcha mavjud full-text tillar; quality/review xatolari eski yaxshi yozuvni bosmasligi.
4. PDF/OCR/jadval/rasm qabul mezonlari va qolgan review holatlari bo'yicha aniq qaror; tasdiqlanmagan OCR completed emas.
5. Laravel Queue/Scheduler/checkpoint/timeout/retry/cleanup hamda 1000 hujjat integration gate'lari; ochiq failures bilan completed bo'lmasligi.
6. Ommaviy foydalanish doirasi va konservativ access siyosati bo'yicha ochiq masalalar hal qilinishi.

P0.1 yakunida to'xtaldi. **P1 yoki keyingi bosqich boshlanmadi. Foydalanuvchining aniq tasdig'i kerak.**

**Yakuniy audit:** 2026-10-28 12:25:31 +05. Birinchi live requestdan hisobot/cleanup/offline qayta testgacha **3121.179 s (52.02 daqiqa)**; boshlang'ich o'qish/setup bu o'lchovga kirmaydi. 150 raw/DB fayl (61957707 bayt) o'chirildi; [cleanup dalili](p0_1/cleanup.json). Raw fayllarsiz 37+16 test qayta o'tdi.
