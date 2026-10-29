# P3 evidence index

2026-10-28. Yakuniy xulosa: [P3_REPORT](../P3_REPORT.md), [SCRAPING_BENCHMARK](../SCRAPING_BENCHMARK.md). P4/1000/full uchun alohida tasdiq kerak. Bu katalogdagi live driver default suite'ga kirmaydi; uni avtomatik ishga tushirmang.

| Dalil | Mazmun |
|---|---|
| [final_tests.txt](final_tests.txt), [final_junit.xml](final_junit.xml) | 110 test/443 assertion; haqiqiy fixed test PostgreSQL, tashqi HTTP taqiqlangan |
| [baseline_tests.txt](baseline_tests.txt) | P3 oldidan 77/306 |
| [pint_final.txt](pint_final.txt), [composer_validate.txt](composer_validate.txt), [platform_requirements.txt](platform_requirements.txt) | Formatter, Composer va platform yakuniy natijasi |
| [development_migration_status.txt](development_migration_status.txt), [testing_migration_status.txt](testing_migration_status.txt) | Ikkala bazada 8 Ran |
| [migration_audit.json](migration_audit.json) | 55 legacy item backfill; 15/45/37 saqlandi, old24failed_jobs hashlar |
| [database_before.json](database_before.json), [database_after.json](database_after.json) | Fixed DB va unrelated table/count inventory |
| [content_audit.json](content_audit.json), [final_idempotency.json](final_idempotency.json) | Old34content aynan teng, 3faqat emptyheading; keyin all40hash teng |
| [requests.jsonl](requests.jsonl), [http_stats.json](http_stats.json) | 289live request, minimum2.000021697s, 289×200; raw body/cookie/token yo'q |
| [discovery_roots.json](discovery_roots.json), [historical_discovery.json](historical_discovery.json) | Olti root bounded probe; identical-query72/72,4page |
| [pending_discovery.json](pending_discovery.json), [pending_summary.txt](pending_summary.txt) | Haqiqiy future-effective pending discovery/import |
| [identity_audit.json](identity_audit.json) | 16group/42node reciprocal graph, 31fulltext/11metadata-only; legal full text yo'q |
| [review_comparison.json](review_comparison.json), [source_changes.json](source_changes.json) | 13review P2/P3; ikki source HTMLga almashgan va fresh metadata |
| [run_4.json](run_4.json), [run_5.json](run_5.json), [run_6.json](run_6.json), [run_7.json](run_7.json) | Durable live run/item/summary/content-hash snapshots |
| [resume_audit.json](resume_audit.json), [queue_restart_terminal.txt](queue_restart_terminal.txt) | Real graceful restart,18job/19item/receipt o'zgarmagan |
| [card_language_probe.json](card_language_probe.json), [final_repeat_summary.txt](final_repeat_summary.txt) | Run6unknown-label hodisasi, fresh probe/final qayta test; root cause isbotlanmagan |
| [pdf_table_regression.json](pdf_table_regression.json) | 8557098p9,9×5/45cell P0.1bilan teng, settings disagree/semantics unverified |
| [alternate_pdf_regression.json](alternate_pdf_regression.json) | Source-discovered fresh36scanpage OCR, provenance/gold/timing |
| [ocr_regions_real.json](ocr_regions_real.json), [ocr_summary.json](ocr_summary.json), [gold_reconciliation.json](gold_reconciliation.json) | Ayni fresh bytes offline replay, region coordinates/confidence,2excerpt CER/WER; certificationfalse |
| [benchmark_results.json](benchmark_results.json), [benchmark_summary.json](benchmark_summary.json) | 4offline variant, fake HTTP/realPG/real controlled OCR |
| [ocr_contention.json](ocr_contention.json), [parallel_alias.json](parallel_alias.json) | 2process CPU OCR va canonical alias race |
| [live_performance.json](live_performance.json), [worker_profile_183851.json](worker_profile_183851.json) | Live final90request/193s va process CPU/RSS |
| [historical_integrity.json](historical_integrity.json) | 289historical SHA, core foundation byte-identical |
| [cleanup.json](cleanup.json), [final_checks.json](final_checks.json) | Private source/temp removal, flags/DB/queue/permission checks |
| [final_terminal.txt](final_terminal.txt) | Terminaldagi birlashtirilgan yakuniy natija |

`initial_*`, `second_*`, `third_*`, `rebuild_function_failure.txt`, `benchmark_initial_unbalanced.json` va formatting failure loglari rivojlantirishdagi muvaffaqiyatsiz urinishlarni ochiq saqlaydi. Yakuniy PASS dalili `final_tests.txt/final_junit.xml`; eski xato loglari final natija deb ishlatilmaydi. Gold replay matcher key-cast xatosi tuzatilgan, reconciliation alohida qayd etilgan.

## Kod va qayta tekshirish

Asosiy o'zgarishlar: `database/migrations/*000006*`, `app/Models/ScrapeRunItem.php`, `app/Models/ScrapeRun.php`, `app/Services/Scraping/{RunItemTracker,ScrapeRunManager,DiscoveryPlanner}.php`, queue jobs/CLI, `app/Scraping/LexUz/` parser/quality inspector/optional Python helper. Universal importer/validator va Document/Identifier/Content/Source/enum baseline bilan teng. Qo'shimcha tests: `DurableTrackingTest`, `DiscoveryPartitionTest`, `ConcurrentItemTest`, `P3ParserQualityTest`; eski queue/foundation testlari tasdiqlangan kontraktlarga moslashtirilgan.

Offline regression:

```bash
vendor/bin/phpunit
vendor/bin/pint --test
composer validate --strict
composer check-platform-reqs
```

`phpunit` faqat `mening_fikrim_active_test`dagi ma'lumotlarni destructive reset qilishi mumkin; production/development nomi actual connection orqali rad etiladi. Test suite'larga bir vaqtda shu test bazasidan foydalanmang. `.env` credentiallarini chiqarish kerak emas.

Read-only development audit/summary:

```bash
php artisan migrate:status
php artisan documents:sync lex --report=7
php artisan documents:sync lex --items=7 --status=review
php docs/p3/final_checks.php
python3 docs/p3/finalize_evidence.py
```

`benchmark.php`, `ocr_contention.php`, `parallel_alias.php` explicit test muhit/Http::fake bilan controlled yozuvlar yaratadi; saved natijalar yuqorida. Bularni regression suite bilan parallel ishlatmang. `live.php` faqat oldingi P3 bounded ruxsatli kampaniyaga tegishli; keyingi bosqich yoki full executionga ruxsat emas. Private PDF/PNG/candidate va temp pointerlar cleanupda o'chirilgan; local PDF replay driver hozir yangi bounded source capture bo'lmasa ishlamaydi. Derived JSON/gold/unit fixturelar qolgan.

Optional PDF layout: `requirements-parser.txt`da pdfplumber0.11.10 pin qilingan. P3 local env P0 rootless venv/toolchainini ishlatdi; boshqa muhitda mos executable/model pathlarini sozlash kerak. Raw originals doimiy fixture sifatida ko'chirilmaydi.
