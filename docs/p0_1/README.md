# P0.1 — test dalillari va qaytarish

Asosiy xulosa: [P0_1_REPORT.md](../P0_1_REPORT.md). Arxitektura takliflari: [P0_1_PLAN_PROPOSALS.md](../P0_1_PLAN_PROPOSALS.md).

Bu P0'dagi tasdiqlangan HTTP, listing, metadata va source cleaning yordamchilarini qayta ishlatadigan eksperimental Python muhitidir. Laravel loyihasi, production importer yoki PostgreSQL integration testi emas. P0 dalillari o'zgartirilmagan. Sinovlar 2026-10-28 snapshotiga tegishli; kelajakdagi source total va matnlar o'zgarishi mumkin.

## Artefaktlar

- `requests.jsonl`, `timing.jsonl`: 139 real HTTP request, hammasi 200; kamida 2.050024 s monotonic start oralig'i. Har bir URL, POST target va response SHA-256 qayd qilingan. Viewstate hash ko'rinishida; raw body vaqtinchalik edi.
- `discovery.json`: 17 asosiy filter/interval testi. `stopped_at_budget` source cap degani emas. Explicit source “hujjat topilmadi” xabari 0 sifatida ajratilgan.
- `all_language_discovery.json`, `court_subtypes.json`, `court_language_gap_example.json`: til filterining amaliy qamrov xatosi. `court_without_status.json` — F bo'yicha qo'shimcha cross-check.
- `large_pagination.json`, `large_all_language_last.json`: katta ro'yxatlarning bosh/oxiri; o'rta sahifalar to'liq aylanilmagan.
- `resume.json`, `historical_split.json`, `overlaps.json`: real replay/checkpoint, total va unique/dublikat dalillari. Tokenning haqiqiy expiry muddati isbotlanmagan.
- `identity_graphs.json`, `identity_results.json`: 4 guruh, 15 ID, 42 tasdiqlangan yo'nalgan til aloqasi. Optional publication fieldining yo'qligi haqiqiy ziddiyatdan ajratilgan.
- `store_audit.json`: vaqtinchalik 4 jadvalli model, 96 chaqiruv, created 1 / unchanged 95, content 3; review'da eski snapshot saqlangan. JSON TEXT natijasi PostgreSQL JSONB tezligi yoki lock kafolati emas.
- `acquired.json`, `html_results.json`, `pdf_results.json`, `comparison.json`: avvalgi 19 review seedning qayta tekshiruvi. Eski 11 pass qayta tekshirilmagan; `17/30` faqat carry-forward bilan kompozit hisob.
- `markdown/`: yaxshilangan kandidat matnlar. Review flaglari bo'lganlarini import qilishga ruxsat yo'q. Jadval koordinatalari qo'shimcha struktura; header ma'nosi avtomatik tasdiqlanmagan.
- `pdf_table_trials.json`: default 11×11, tolerance 8 da 9×5 bo'lgan bitta real jadval. Buni barcha PDFlar uchun universal sozlama deb qabul qilmaslik kerak.
- `ocr_gold.json`: qo'lda ko'rilgan ikki qisqa band, normalizatsiya qilingan CER. Hujjatning barcha sahifalari uchun accuracy sertifikati emas.
- `regression_fixtures.json`: kichik haqiqiy DOM parchalari; to'liq asl HTML emas.
- `test_output.txt`: 37 ta yangi test. `p0_regression_output.txt`: avvalgi 16 test. Real HTTP, offline replay, SQLite modeli va sun'iy fault testlari hisobotda ajratilgan.

## Offline qaytarish

```bash
uv venv /tmp/lex-p01-tools
uv pip install --python /tmp/lex-p01-tools/bin/python -r docs/p0_1/requirements.txt
/tmp/lex-p01-tools/bin/python docs/p0_1/test_p01.py
/tmp/lex-p01-tools/bin/python docs/p0_1/model_audit.py
/tmp/lex-p01-tools/bin/python docs/p0/test_spike.py
```

Bu buyruqlar Lex.uz'ga so'rov yubormaydi. P0 regressiya testi localhost HTTP fault serverini ochadi; model vaqtinchalik SQLite yaratib o'chiradi. `Store` faqat Lex identity kontrakti tajribasi; source=2 testi namespace ajratilishini nazorat qiladi, boshqa real providerga ulanmaydi.

## Live qaytarish

Yangi run uchun alohida output katalogini bering: log append bo'ladi, mavjud snapshot bilan aralashtirmang. Default output ushbu `p0_1/` katalogi. Skriptlar fiksatsiyalangan kichik sample va davrlarga mo'ljallangan. Request byudjeti cheklangan; barcha hujjatlarni yuklaydigan rejim yo'q.

```bash
export LEX_P01_TMP="$(mktemp -d /tmp/lex-p01-replay-XXXXXX)"
export LEX_P01_OUTPUT="$(mktemp -d /tmp/lex-p01-evidence-XXXXXX)"
export LEX_P01_OCR_ROOT=/path/to/rootless-tesseract
/tmp/lex-p01-tools/bin/python docs/p0_1/live_checks.py
/tmp/lex-p01-tools/bin/python docs/p0_1/supplemental_live.py
/tmp/lex-p01-tools/bin/python docs/p0_1/html_checks.py
/tmp/lex-p01-tools/bin/python docs/p0_1/pdf_checks.py
/tmp/lex-p01-tools/bin/python docs/p0_1/audit_results.py
/tmp/lex-p01-tools/bin/python docs/p0_1/model_audit.py
/tmp/lex-p01-tools/bin/python docs/p0_1/test_p01.py
```

HTTP buyruqlarini bitta worker bilan ketma-ket bajaring. `get` bir run ichida bir URL body'sini qayta ishlatadi. Poppler `pdftotext/pdftoppm`, Tesseract 5.3.4 va `uzb_cyrl`, `uzb`, `rus`, `eng` traineddata ishlatildi. Rootless Tesseract katalog layouti P0 README'da yozilgan. Pdfplumber bu spike'da geometry/table tahlili uchun ishlatildi; Laravel uchun yangi production dependency tanlangani yo'q.

Raw HTML/PDF/PNG va SQLite'ni yakunda, xatoda ham tozalang. Ushbu run'dagi cleanup `cleanup.json`da qayd qilinadi. Kelajakdagi umumiy importerda `try/finally` va uzilgan run uchun startup cleanup kerak. Helperdagi OCR sahifa rasmlari `finally`da o'chiriladi.

## Quality qoidalari

Numeric `<sup>` Unicode superscriptga o'tkazildi; bo'sh sup chiqarildi. Nonnumeric/matematik sup `^{...}` ko'rinishida saqlanib review bo'ladi. Murakkab jadvalning har bir katagi `R/C/rowspan/colspan` bilan olinadi, ammo header semantikasi review'da. Rasm, noaniq PDF struktura, barcha tasdiqlanmagan OCR, noma'lum status va source ziddiyati auto-save'ni to'sadi. OCR confidence 90 dan past yoki confidence<70 ulushi 5% dan katta bo'lsa qo'shimcha review; yuqori confidence o'zi auto-accept bermaydi. 30% dan katta qisqarish himoyasi eksperimental, hali tasdiqlangan production threshold emas.

Haqiqiy expiry yuz bermagan; unit test invalid/cycling state holatida durable checkpointni oldinga surmaslikni tekshiradi. PostgreSQL JSONB lock/index/query va Laravel Queue testlari keyingi tasdiqlangan implementatsiya bosqichiga qolgan.
