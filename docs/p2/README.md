# P2 dalillari

Asosiy hujjat: [P2_REPORT](../P2_REPORT.md). Bu katalog P2ning cheklangan real campaigni va offline regressionlarini saqlaydi. P0/P0.1/P1 dalillari alohida, o'zgartirilmagan.

- `test_output.txt`, `junit.xml`: final offline suite, 77 test / 306 assertion. Real PostgreSQL, HTTP fake/virtual clock; real Lex so'rovi emas.
- `p1_regression_output.txt`: eski 46 test / 187 assertion fixed test bazada. `offline_initial*`, `integration_*`, `language_guard_development_failure.txt` kabi eski loglar developmentdagi muvaffaqiyatsiz urinishlarni ham saqlaydi; final suite ularning o'rnini belgilaydi.
- `discovery_live.json`: olti real root birinchi page va tarixiy nat segmentining 72/72, 4-page fresh replay natijasi.
- `run_1.json`, `run_2.json`, `run_3.json`: haqiqiy development DB run snapshotlari; 27 sample first/repeat, keyin 1 verified alias orqali real discovery→queue.
- `sample_results.json`, `campaign_stats.json`: barcha 30 guruhning natijasi. `pdf=6`, `ocr=2` o'zaro alohida formatlar; jami 8 PDF.
- `requests.jsonl`: real request logining nusxasi, raw response/form/cookie/Viewstate yo'q; `http_stats.json` agregati. Daily `.log` lokal nusxa ham mavjud bo'lishi mumkin.
- `inactive_live.json`, `inactive_update.json`, `scan_safe_update.json`: source inactive tekshiruvi, isolated test DBdagi controlled prior state va old-content protection. Controlled o'tmish/source accuracy sifatida talqin qilinmaydi.
- `database_before.json`, `schema_comparison.json`, `database_after.json`: faqat credentiallarsiz katalog/row/hash diagnostikasi. `historical_integrity.json`: 214 eski fayl hash'i teng.
- `ocr_spotcheck.json`: yangi renderdan bitta 42 normalized belgili excerpt, document-level OCR sertifikati emas.
- `cleanup.json`: private raw HTML/PDF/PNG/candidate fayllar tozalangani. Ular bu katalogga ko'chirilmagan.

Offline natijani qayta tekshirish:

```bash
vendor/bin/phpunit
vendor/bin/pint --test
```

Suite faqat `mening_fikrim_active_test`ga yozadi va shu bazadagi controlled test rowlarini tozalashi mumkin; developmentga tegmaydi. Ayni test bazada ikki suite'ni parallel yurgizmang.

Saqlangan dalillarni o'qish/agregatsiya qilish:

```bash
python3 docs/p2/finalize_evidence.py
php docs/p2/audit_database.php
php artisan documents:sync lex --report=2
```

`finalize_evidence.py` saqlangan JSON/JSONLdan hisoblaydi, network/DB yozuvi yo'q. `audit_database.php` bazalarni read-only transaction bilan o'qiydi va yangi audit JSONni yangilaydi; DBga yozmaydi. Keyin test suite yoki import ishlatilsa, yangi DB auditning row countlari tarixiy campaign snapshotlaridan farq qilishi mumkin.

`run_live.php` — qo'lda chaqiriladigan P2 evidence driver; default tests, web yoki scheduler uni yuklamaydi. U real HTTP/queue/DB yozuvlarini bajarishi mumkin. Eski `/tmp/lex-p2-*` manbalar yakunda o'chirilgan; inactive-update raw candidatelarsiz qayta ishga tushmaydi. Real campaignni takrorlash avtomatik ish emas: yangi private temp katalog, explicit live flag, fixed DB va bitta worker bilan foydalanuvchi tasdiqlagan kichik doirada bajariladi; raw temp yakunda o'chiriladi. Asosiy safe CLI yo'riqnomasi [README](../../README.md)da.

Run1ning 789 s recorded durationiga terminal finished_at bugidan report kechikishi kirgan. Worker log oynasi taxminan 666 s; bug tuzatilgach run2 470 s va run3 17 s stabil qayd qilindi. Birinchi metricni sof throughput sifatida ishlatmang. Real 403/429/5xx/expiry kuzatilmagan; ular offline fault testlari.
