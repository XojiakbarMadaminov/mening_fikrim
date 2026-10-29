# P5 — Corpus Collection Pilot

Pilot 2026-10-28da Asia/Tashkent bo‘yicha boshlandi. Laravel13.39.0 / PHP8.5.11 / PostgreSQL17.11. Hisobot faqat yozilgan real evidencega asoslangan; yangi parser/OCR/AI,1000/full crawl,scheduling yoki deployment bajarilmadi.

## A. Executive summary

**NO-GO (keyingi1000pilot uchun)** — 158 NEW canonical hujjat, jami **187document/433content**. Quality: **344verified/89needs_review**. Yangi matnlar:370, updated hash representations:0, unchanged full-text attempts:8 (preceding-run snapshot basis,alias skip excluded).

**1229/4000 real HTTP request**, controller 2,497.235s, active worker 2,377.950s. Technical failed item:0; yangi failed job:0; historical24saqlandi. Stop: `bounded_source_selection_budget_exhausted`, 2026-10-29T00:17:16+05:00. 360distinct source-ID cap tugadi; HTTPbudget tugamadi (2771request qoldi). 200targetdan 42NEW canonical yetishmadi; xavfsizlik guardi oshirilmadi. Pending jobs:0, pending items:0. Targetga yetilmagan bo‘lsa haqiqiy stop sababi shu; source-ID soni canonical success deb berilmadi.

[Machine summary](p5/final_summary.json), [live terminal](p5/live_terminal.txt), [request evidence](p5/requests.jsonl). Bounded runlar strict global completion emas, completed_with_errors sifatida qolishi mumkin; bu technical job failure bilan teng emas. Recommendation yangi1000runni boshlash ruxsati emas.

## B. Initial database state / Stage A

|Ko‘rsatkich|Before|After|
|---|---:|---:|
|documents|29|187|
|document_identifiers|82|509|
|document_contents|63|433|
|verified contents|42|344|
|needs_review contents|21|89|
|scrape_runs|10|29|
|scrape_run_items|169|532|
|failed_jobs|24|24|
|jobs|0|0|
|migrations|9|9|

Actual fixeddevelopment `mening_fikrim_active`, isolatedtests `mening_fikrim_active_test`. Reset/truncate/drop-development yo‘q. Baseline130tests/581assertions PASS; final143tests/619assertions PASS, Pint PASS, P0offline16/P0.1offline37PASS. Queue/cache database; timeout900<lease930<retry_after960,1worker,>=2000ms, live/scheduler defaultfalse, scheduled tasks: 0. Jami 9 migration applied; **P5migration/schema change0**.

Earlier isolated test-DB restore command suite teardown bilan overlap qilib1migration error berdi. Development o‘zgarmadi; xato [log](p5/test_restore_race.txt)da saqlandi, sequential full rerun green, so‘ng test schema/2source seed tiklandi. Xato tuzatilgach yakuniy 143/619 rerun muvaffaqiyatli o‘tdi. [Baseline](p5/database_baseline.json), [preflight](p5/stage_a_results.json), [tests](p5/final_tests.txt), [JUnit](p5/final_junit.xml), [formatter](p5/pint.txt).

## C. Implementation and stage results

Existing discovery→shared HTTP→HTML/PDF/Tesseract extraction→universal importer→content quality/persistence/protected candidate→durable run-item flow saqlandi. Source adapter business writes qilmaydi; new PilotCampaign faqat mavjud database-cache coordination va run checkpoint campaign IDdan foydalanadi. Legal status/quality/parser/identity qoidalari yumshatilmadi. O‘zgargan9PHP/testfayl:[inventory](p5/changed_files.json); asosiy qo‘shimchalar PilotCampaign, PilotStoppedException va documents:pilot CLI.

Finite4000campaign request cap:200discovery/replay+3000document/alias+600retry/redirect+200control/targeting planning allowance. Bu kafolatlangan cost emas; hard ceiling4000. Run1..30va400cap saqlandi. Har discovery/card/PDF/retry/redirect umumiy HTTP lock ichida budgetdan **request oldidan** joy oladi. Per-run context o‘zgarishi cumulative budgetni tiklamaydi; cacheforever state no24hexpiry.403/429darhol,2consecutive5xx/network error terminal stop; keyingi queue receipt olinmasdan worker chiqadi. Stopped campaign uchun reset yoki auto-resume command yo‘q. Cacheclear qilmang. Existing receipt original campaign state bo‘lmasa networkka o‘tmaydi.

New canonical count: baseline max document IDdan keyingi Lex source rows; universal identity/importer/FK/UNIQUE saqlandi. Bir source-ID job ko‘pi bilan bitta document yaratadi; batch source-ID limit remaining stage allowance’dan oshmaydi. Stage advance exact30/100new count,empty queue va controller integrity auditdan keyin. Driver source-ID selection ham 360 bilan bounded. Bu preflightda qo‘shilgan ikkinchi safety limit; talabdagi 200 canonical targetni source IDga almashtirmaydi. Nominal allocation 360/200=1.8 ID/target, real yield esa 360/158=2.278 ID/new canonical bo‘ldi. HTTP budget yetarli qolgan bo‘lsa ham controller shu oldindan yozilgan guardda to‘xtadi. Bu planning cheklovi keyingi campaign OLDIDAN qayta hisoblanishi kerak; joriy guard avtomatik oshirilmadi.

|Stage|Cumulative new / target|New docs|New contents|Verified / review delta|Selected IDs|Alias skips|Technical failed|Import HTTP|Run elapsed sum s|
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
|B|30/30|30|69|61 / 8|59|19|0|216|424.000|
|C|100/100|70|168|136 / 32|154|68|0|487|982.000|
|D|158/200|58|133|105 / 28|150|65|0|471|958.000|

Import HTTP discoveryni qamramaydi; umumiy1229request ichida barcha discovery/replay overhead ham bor. Stage before/after snapshots va final integrity fayllari alohida. Completed bo‘lmagan stage completed deb olinmaydi.

|Run|Kind|Source IDs|New canonical|Already existing in run|Alias skips|New contents|HTTP|Technical failed|
|---|---|---:|---:|---:|---:|---:|---:|---:|
|11|new_collection|20|10|0|8|24|68|0|
|12|repeat_control|3|0|3|0|0|16|0|
|13|new_collection|20|10|0|8|21|60|0|
|14|new_collection|10|5|0|2|12|44|0|
|15|new_collection|5|4|0|1|10|24|0|
|16|new_collection|1|1|0|0|2|4|0|
|17|new_collection|30|12|0|14|29|93|0|
|18|new_collection|30|14|0|14|33|84|0|
|19|new_collection|30|13|0|15|32|84|0|
|20|new_collection|30|12|0|14|28|92|0|
|21|new_collection|19|9|0|8|23|70|0|
|22|new_collection|10|6|0|2|14|44|0|
|23|new_collection|4|3|0|1|8|18|0|
|24|new_collection|1|1|0|0|1|2|0|
|25|new_collection|30|14|0|8|29|114|0|
|26|new_collection|30|12|0|9|27|116|0|
|27|new_collection|30|12|0|12|23|97|0|
|28|new_collection|30|10|0|16|24|84|0|
|29|new_collection|30|10|0|20|30|60|0|

Discovered unique source IDs:448; selected source-ID attempts: 363(360distinct). Stored canonical groups:158; baseline-existing canonical discovered:3(9listing IDs), touched:0. Same-campaign repeatlar per-run existing sifatida alohida. Identity proven ammo unsaved groups:0; unassociated review items:8. Alias skip:152; queue oldidan known identifier sifatida chiqarilgan listing IDs:81. New identifier rows:427; identifier/language rowlar new-document targetga qo‘shilmadi.

## D. Discovery coverage

Actual nat/loc/int/tech/court/oliy rootlar, statusY(active+pending), **til filtrisiz**, inclusive sourcefrom/to. Har partition fresh session bilan2marta, current ASP.NET hidden-state POST pagination,8page cap. Source total, unique IDs,end termination,prefix/order va replay dalili saqlandi. Candidate order: partitionlar bo‘ylab round-robin, mavjud identifier/oldin attempted IDlarni chiqarish; identity hali faqat scraper/importerda tasdiqlanadi.

|Root|Date partition|Listing total|Unique IDs|Pages|Coverage|Replay stable|
|---|---|---:|---:|---:|---|---|
|nat|01.01.2010–31.01.2010|72|72|4|complete|True|
|loc|01.01.2020–31.01.2020|93|93|5|complete|True|
|int|01.01.2020–31.12.2020|7|7|1|complete|True|
|tech|01.01.2026–28.10.2026|105|105|6|complete|True|
|court|01.01.2020–31.12.2020|11|11|1|complete|True|
|oliy|01.01.2020–31.12.2020|1429|160|8|INCOMPLETE: page cap|True|

Nat2010partition qo‘shimcha1pageda to‘xtatildi, prefix diskka yozildi, fresh sessionda qayta yurildi; oldprefix20IDtakror ko‘rildi, full72ID/total/end qayta mos keldi ([resume proof](p5/discovery_resume.json)). Bu real shared scraper replay testi; durable queue/checkpoint crash regressiyalari offline PGsuite’da ham bor. Haqiqiy server tokenexpiry kuzatilmadi. Incomplete oliypartition to‘liq deb belgilanmadi; source countcanonical count emas. **Global Lex.uz coverage isbotlanmadi.** [Coverage JSON](p5/discovery_coverage.json), [sampling pool](p5/sampling_pool.json).

Enumerated listing IDsning final corpusdagi mapping holati (canonical soni observed IDs uchun,globaltotal uchun emas;bo‘limlararo canonicaloverlap sabab group countlar yig‘ilmaydi):

|Root|Enumerated sourceIDs|Mapped IDs|Unmapped IDs|Stored canonical groups|Beyond pagecap source records|
|---|---:|---:|---:|---:|---:|
|nat|72|69|3|21|0|
|loc|93|93|0|47|0|
|int|7|7|0|2|0|
|tech|105|103|2|35|0|
|court|11|11|0|5|0|
|oliy|160|153|7|51|1269|

[Partition mapping audit](p5/discovery_partition_statistics.json). Bounded import source listing to‘liq enumeration bilan bir xil tushuncha emas; unmapped IDs/further pages ochiq qamrovdir.

## E. Extraction and quality

Unique persisted representations observed:370; HTML:368, physicalPDF:2(rawformat pdf=1,ocr=1), text-layerPDF:1, OCR representations:1/7pages. Format va chars har real representationattempt uchun [extraction_statistics](p5/extraction_statistics.json)da; alias skip va metadata-only extraction attempt deb olinmagan.

Yangi content languages:`{"ru": 61, "uz-Cyrl": 157, "uz-Latn": 151, "en": 1}`. Newly persisted review:68; newly verified:302. Per-fulltext-attempt persistence success:100.000%; new-content automated verification rate:81.622%. Denominatorlar boshqa: metadata/identity rejectionlar persistence denominatoriga yashirin qo‘shilmadi, yuqorida/alohida URLsda ko‘rsatilgan.

Extraction-failed items:0; failed language representations:0; technical failed items:0. Empty fake content row yo‘q. Usable needs_review text saqlanadi, original reason codes JSONBda qoladi. Verified inson/yuridik certification emas. New review issues:`{"complex_table_semantics_review": 58, "quality_review": 67, "missing_document_heading": 3, "invalid_text": 5, "unsupported_superscript": 17, "pdf_image_semantics_unverified": 2, "pdf_language_coverage_unconfirmed": 2, "pdf_reading_order_unverified": 2, "pdf_structure_unverified": 2, "unextracted_images": 12, "low_ocr_confidence": 1, "ocr_critical_tokens_unverified": 1, "ocr_source_identifier_not_certified": 1, "uncertified_ocr": 1}`.

Real OCR natijalari page/timing/issues bilan evidence faylida; confidence document certification emas.
PDF strukturasi/order/language uncertaintylari reviewligicha; yangi PDF extraction technology yo‘q.

Unassociated/problematic items (har biri durable run-itemda; muvaffaqiyat deb berilmadi):

|URL|Outcome|Sabab|Final canonical mapping|
|---|---|---|---|
|[1597458](https://lex.uz/uz/docs/1597458)|review|Unknown source document language: Русча|45|
|[1587862](https://lex.uz/uz/docs/1587862)|review|{"identity":["Conflicting component requisites: bodies"]}|unassociated|
|[-1587862](https://lex.uz/uz/docs/-1587862)|review|{"identity":["Conflicting component requisites: bodies"]}|unassociated|
|[1587860](https://lex.uz/uz/docs/1587860)|review|{"identity":["Conflicting component requisites: bodies"]}|unassociated|
|[8354875](https://lex.uz/uz/docs/8354875)|review|{"identity":["Missing node or nonreciprocal source link."]}|unassociated|
|[-8345596](https://lex.uz/uz/docs/-8345596)|review|{"identity":["Missing node or nonreciprocal source link."]}|unassociated|
|[8119972](https://lex.uz/uz/docs/8119972)|review|Unknown source document language: Ruscha|148|
|[5210585](https://lex.uz/uz/docs/5210585)|review|Unknown source document language: Ruscha|159|

Real format examples:

|Source|Format|Pages|Language|Stored chars|Quality|Parse / OCR seconds|
|---|---|---:|---|---:|---|---:|
|[8345596](https://lex.uz/uz/docs/8345596)|text-layer PDF|34|uz-Latn|57197|needs_review|6.743 /0|
|[4797522](https://lex.uz/uz/docs/4797522)|natural scanned PDF / existing Tesseract|7|uz-Cyrl|7316|needs_review|21.030 /20.513|

Bu ikki realPDFsample corpusformat distribution yokiOCRaccuracycertification emas. Low confidence/critical-token/order/language/image warnings saqlandi; matn verifiedga ko‘tarilmadi. New legal status:`{"pending": 2, "active": 156}`; source document type counts:`{"Положение": 1, "Соглашение": 2, "Постановление": 65, "Правила": 6, "Приговор": 1, "Распоряжение": 1, "Определение": 1, "Нормативы": 1, "Приказ": 33, "Решение": 47}`. Legal status quality enumdan mustaqil.

8unassociated review **item**dan3tasi keyin boshqa proven alias orqali saqlangan canonicalga bog‘langan (jadval finalmapping). Qolgan attemptedsourceIDs5ta; bu5canonicalhujjat degani emas. Bundan tashqari7listingIDcap tufayli tanlanmagan. UnknownRuscha/Русча labels va body-requisite/nonreciprocal-link ziddiyatlarini guardni yumshatib taxminan mergeqilmadik. Sourcecaption/rawcardni bu pilotdan keyin qayta fetchqilmadik; keyingi fixture-backedfixlar alohida boundedtrafficqarori bilan.

## F. Performance

|Metric|Observed|
|---|---:|
|Controller end-to-end s|2,497.235|
|Active worker s|2,377.950|
|Operator pause in controller s|0|
|Shared pacing/backoff wait s|2,267.142|
|HTTP request/response time s|115.336|
|New canonical/h worker / end-to-end|239.198 / 227.772|
|New content/h worker / end-to-end|560.146 / 533.390|
|Requests/newcanonical incl discovery/repeat/alias|7.778|
|HTTP / queue retry attempts|0 / 0|
|Minimum request start interval s|2.000|
|PHP peak MiB|108.500|
|Max self / child RSS MiB|241.793 / 174.289|
|HTML parse p50/p95 s|0.006 / 0.087|
|PDF parse p50/p95 s|6.743 / 21.030|
|Measured OCR total s|20.513 (1observations)|
|Queue wait p50/p95 s|68.791 / 182.700|
|DB transaction+reconciliation estimate s|6.362|
|All worker DB query seconds|30.898|

Worker time pacing/network/extraction/DBni o‘z ichiga oladi. Controller minus worker:discovery,replay,audit,queue orchestration,fileI/O; operator pauzasi emas. Pacing/HTTP/worker vaqtlarini bir-biriga qayta qo‘shmang. Queue waiting percentillari itemlar bo‘ylab, wallga additive emas. DB transaction estimate fetch_parse_import−fetch_parse farqi, faqatwrite-only exactmeter emas. RSSpeaklar individual, yig‘indi simultaneousRAM emas. [Performance](p5/performance.json), [worker profiles](p5/worker_profiles.json), [HTTP](p5/http_statistics.json). Client start intervalining aniq minimumi **2.000131773s**. Joriy storage o‘lchovlari:[storage statistics](p5/storage_statistics.json); physical baseline size olinmagani uchun physical growth o‘lchangan deb ko‘rsatilmaydi.

Planning projections (ruxsat yoki kafolat emas):

|Documents|Same observed mix hours|3×planning hours|Requests incl observed overhead|10%docs×assumed60sOCR sensitivity|
|---:|---:|---:|---:|---:|
|1000|4.390|13.171|7779|+1.667h|
|10000|43.904|131.711|77785|+16.667h|
|100000|439.036|1,317.107|777849|+166.667h|

Same-mix linear scaling observed discovery/alias/repeat/retry overheadni saqlaydi.1×–3×planning scenario confidence interval emas; yangi date-partition discovery,OCRdistribution,large tables,access stops,source changes yoki100000scope pagination xarajati boshqacha bo‘lishi mumkin.60sOCRsensitivlik **taxmin**, real benchmark emas.0observedretry kelajakda0retry kafolati emas. Bu sample source access/1000/full import capacityni tasdiqlamaydi.

## G. Integrity, resume and data protection

Final audit errors:`[]`; duplicate language/alias/orphan counts:`{"duplicate_languages": 0, "duplicate_aliases": 0, "orphan_contents": 0, "orphan_identifiers": 0}`. Original29canonical anchors,82identifier associations,63content IDs/languages/hashes/quality va24failed-jobrowhashsaqlandi. Verified content avtomatik needs_review bilan almashtirilmadi. P5da bunday conflicting live candidate uchramadi; himoya P4/P5 realPG regressionlari va originalhash auditlari bilan tekshirildi; P4candidate staging/shrink/stale policy va realPGprotection regressiyalari saqlandi. Har batch/stage snapshotdalili mavjud; fake/empty text yo‘q, legal status independent.

Worker6jobdan keyin betweentasks to‘xtadi:5completed item,15unfinished queue receipt; explicitresume receipt IDs/payloadlarini o‘zgartirmadi va restart yakunladi ([proof](p5/interruption_resume.json)).3group repeat document/content counts va alltext hashes stable ([proof](p5/repeat_import.json)). Bu graceful restart; hard kill/crash,lease/retry/backoff/tokenfencing realPGoffline regressiyalarida, P5devdata corruption bilan simulyatsiya qilinmadi.

Historical P0–P4protected artifacts:530, changed:0. Yangi table, migration yoki extraction engine qo‘shilmadi; mavjud Tesseract real7pagefallbackda ishladi. No verified downgrade or deletion. Database-cache state finite pilot coordination; cacheeviction/resetdan guard failclosed bo‘lsa ham independent archival registry emas. [Integrity](p5/integrity_audit.json), [original row preservation](p5/preservation.json), [final environment/test-schema checks](p5/final_checks.json), [historical audit](p5/historical_integrity.json).

## H. Remaining risks

- Globalcoverage/undatedrecords/sourcecaps/real tokenexpiry va barcha partitionlar isbotlanmagan; incomplete partition aniq ochiq. Public bulk permission/rate threshold isbotlanmadi.403/429yo‘qligi unrestrictedpermission emas.
- PDF/OCR/table/image/order/language uncertaintylar P4qoidalari bilan saqlanadi. Sampleda yo‘q formatlarga throughput/quality extrapolation kuchsiz. Manual legal accuracy QA bajarilmadi.
- Raw HTML/PDFarchive yo‘q; reprocessing freshfetch talab qiladi, source o‘zgarishi mumkin. Bitta latestcontent/pendingcandidate, fullversionhistory emas.
- Metadata,largeMarkdown,tablegeometryJSONB,queue/ledger va logs storagegrowth masshtabda profile qilinmagan. Cacheclear campaign state yo‘qotishi mumkin; stopped state avtomatik ochilmaydi.
- Pilot singlehost/singleworker;controller double-launch prevention relies on the documented single-controller operation rather than a distributed campaign lease; multi-daymemoryleak,serverrestart,budgetretention,multi-hostclock/fairness va1000/100000scale resilience kafolatlanmagan.

## I. Exact commands and next step

Read-only:

```bash
php artisan documents:pilot status
php artisan documents:sync lex --report=29
php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run
```

Normal continuation **faqat hali open campaign**,originalprogress/queueauditdan keyin:

```bash
SCRAPING_LIVE_ENABLED=true php docs/p5/pilot.php execute
```

Driver originalbudgetni saqlab,unfinished receiptsni resume qiladi; already stoppedcampaign bilan network boshlamaydi. Generic bounded sync+worker shakli quyidagicha, ammo **hozirgi terminal stopni chetlab o‘tish uchun ishlatmang**:

```bash
SCRAPING_LIVE_ENABLED=true php artisan documents:sync lex --mode=full --limit=20 --section=nat --from=01.01.2010 --to=31.01.2010
SCRAPING_LIVE_ENABLED=true php artisan queue:work database --queue=scraping --tries=3 --timeout=900 --stop-when-empty
```

Offline quality recheck:

```bash
php artisan documents:quality-recheck lex --status=needs_review --limit=20 --dry-run
php artisan documents:quality-recheck lex --status=needs_review --limit=20
```

Initial campaign yaratish uchun ishlatilgan aniq buyruq `php artisan documents:pilot start --id=p5-20261028 --requests=4000 --target=200`; bu ID/budget qayta yaratilmaydi. Stage advance controller auditdan keyin `documents:pilot advance --stage=100`/`--stage=200` bilan bir xil gate’dan o‘tadi. Manualstop:`php artisan documents:pilot stop --reason=operator_stop`. Stoppedcampaignni ochadigan command yo‘q; avval reason/data/budgetni ko‘rib, alohida yangi authorization asosida operatorqarori kerak.

Recommendation:**NO-GO keyingi1000uchun**; controlledcollection safety/integrity o‘tdi,exact200gate ochiq. Avval qolgan42NEWcanonical uchun alohida tasdiqlanadigan bounded supplementation: observed360/158=2.278source-ID/newcanonical sabab sourcecapni kampaniyadan OLDIN qayta hisoblash kerak (same-yield200uchun taxmin456sourceID, kafolat emas);360guardmidcampaign oshirilmadi. Keyingi1000pilotdan oldin incomplete datepartitionlarni bo‘lish,PDF/OCRtargetedcoverage va manualQA,sourceaccess/storagelongrunshartlarini ko‘rib chiqing. **1000pilot/fullcrawl/scheduler avtomatik boshlanmadi.**
