# P0 tajriba muhiti

Bu katalog yangi yozilgan, chegaralangan technical spike va dalillardir. Laravel loyihasi emas. `sample_import.py` faqat belgilangan **30 ta** seed hujjatni ishlaydi; production PostgreSQL ulanishi yo'q. Kelajakdagi ilova kodi uchun tayyor adapter deb qabul qilinmasin.

Asosiy xulosa: [P0_REPORT.md](../P0_REPORT.md).

## Dalillar

- `requests.jsonl`: Lex.uz'ga 171 haqiqiy urinish, URL/metod, POST target, vaqt, HTTP natija, hajm va SHA-256. Viewstate qiymatlari hash bilan qayd qilingan. Lokal fault injection bu logga kiritilmagan.
- `discovery.json`, `final_probes.json`: kichik segmentlar, to'liq aylanilgan sahifalar, filter va PDF alias testlari. Katta bo'limlarda faqat birinchi sahifa tekshirilgan.
- `sample_manifest.json`: 30 seed, har bir til URL'i, metadata, format, hash, sahifalar va sifat muammolari. `parsed` matn ajratilganini, `strict_quality_pass` esa cheklangan sifat mezonlari o'tganini anglatadi. `skipped` sahifalar faqat tarjima metadata/xabaridir.
- `markdown/`: 62 matn varianti va alohida `skipped` deb belgilangan 15 metadata xabari. PDF/OCR fayl nomidagi til boshlang'ich source label bo'lishi mumkin; tekshirilgan til `sample_manifest.json` ichida. Bu fayllarni ko'r-ko'rona import qilish mumkin emas.
- `source_fixtures.json`: haqiqiy javoblardan qisqa DOM parchalari, kartochka fieldlari, til kontrollari, joriy tahrir belgisi va provenance hash. To'liq asl HTML/PDF emas.
- `test_output.txt`: 16 ta offline/lokal test natijasi. 403, 429 va timeout testlari localhost'da sun'iy yaratilgan; ular Lex.uz serverining rate limitini isbotlamaydi.
- `import_stats.json`, `temp_db_audit.json`, `access_feed.json`: hisoblar va chegaralar.

## Offline test

Python 3.12 ishlatildi. Avval izolyatsiyalangan virtual muhit yarating:

```bash
uv venv /tmp/lex-p0-tools
uv pip install --python /tmp/lex-p0-tools/bin/python -r docs/p0/requirements.txt
/tmp/lex-p0-tools/bin/python docs/p0/test_spike.py
```

Bu test Lex.uz'ga so'rov yubormaydi. Qisqa haqiqiy source fixturelari va saqlangan Markdownni tekshiradi; localhost HTTP server ochadi. 30 hujjatning barcha matnini huquqiy ekspertiza qilish o'rnini bosmaydi.

## Live tajribani qaytarish

Source javoblari o'zgarishi mumkin. 89/80/593 kabi acceptance kutilmalari aynan 2026-10-28 snapshotiga tegishli. Yangi run uchun avval mavjud derived dalillarni boshqa katalogga nusxalang; skriptlar shu katalogdagi manifestdan resume qiladi va request logga qo'shib yozadi.

```bash
export LEX_P0_TMP="$(mktemp -d /tmp/lex-p0-replay-XXXXXX)"
/tmp/lex-p0-tools/bin/python docs/p0/http_probe.py
/tmp/lex-p0-tools/bin/python docs/p0/discovery_probe.py
/tmp/lex-p0-tools/bin/python docs/p0/sample_import.py
/tmp/lex-p0-tools/bin/python docs/p0/final_probes.py
/tmp/lex-p0-tools/bin/python docs/p0/finalize_evidence.py
```

Live skriptlarni ketma-ket, bitta worker bilan ishga tushiring. Global interval 2 soniya; HTTP timeout (connect/read) 10/30 soniya, test importida 10/90 soniya; retry ko'pi bilan 3 urinish. Bu P0 kodining sozlamalari; tasdiqlangan Laravel arxitekturasini o'zgartirmaydi. Ishlab chiqarish implementatsiyasida HTML 30/PDF 90 soniya ajratilishi, HTTP-date shaklidagi `Retry-After` va umumiy job muddati alohida testlanishi kerak.

PDF uchun Poppler 24.02.0, OCR uchun Tesseract 5.3.4 ishlatildi. OCR tizimga global o'rnatilmadi: Ubuntu paketlari oddiy user sifatida `$LEX_P0_TMP/ocr` ichiga ochildi. Shu layoutni qaytarish uchun `apt download` bilan `tesseract-ocr`, `libtesseract5`, `liblept5`, `tesseract-ocr-eng`, `tesseract-ocr-rus`, `tesseract-ocr-uzb`, `tesseract-ocr-uzb-cyrl` paketlarini oling va `dpkg-deb -x` bilan shu katalogga oching. Skanlarda `uzb_cyrl+uzb` modeli ishlatiladi; title tili PDF ichidagi yozuvga mos bo'lmasligi mumkin.

PDF fallback mezoni: sahifada 100 tadan kam text-layer belgi va rasm bo'lsa OCR. Bu kam belgili watermarkli tabiiy skanlarni ushbu namunalarda tutdi, lekin uzun watermarkli scan yoki qisman buzilgan matn qatlamini to'liq aniqlash isbotlanmagan. Bir hujjatga OCR chegarasi 20 sahifa; undan katta scan incomplete bo'lishi kerak. Ushbu run'da chegara oshmadi. OCR sahifa rasmlari `finally`da o'chiriladi; qolgan asl fayllar run yakunida tozalanadi.

`finalize_evidence.py`dagi `-8553692` ziddiyat belgisi inson ko'rgan aniq fixturega tegishli. Bu barcha hujjatlarning kuchga kirish sanasini matndan avtomatik aniqlaydigan algoritm emas. Tesseract natijasining belgi/so'z xatolik darajasi o'lchanmagan.

Davom ettirish: P0 hisobotidagi ochiq masalalar va foydalanuvchining aniq tasdig'i. P1/P2 yoki full import uchun ushbu skriptlar ruxsat bermaydi.
