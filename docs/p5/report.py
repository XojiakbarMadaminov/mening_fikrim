"""Render the P5 report from recorded evidence. Offline only."""
from pathlib import Path
import json
import xml.etree.ElementTree as ET
P=Path(__file__).resolve().parent
D=P.parent
read=lambda n:json.loads((P/(n+'.json')).read_text())
tests=ET.parse(P/'final_junit.xml').getroot()[0].attrib
s=read('final_summary'); b=read('database_baseline'); f=read('integrity_audit'); q=read('quality_statistics'); e=read('extraction_statistics'); p=read('performance'); h=read('http_statistics'); stages=read('stage_statistics'); runs=read('run_statistics'); coverage=read('discovery_coverage'); storage=read('storage_statistics'); hist=read('historical_integrity'); partition_stats=read('discovery_partition_statistics')
def n(x): return 'o‘lchanmagan' if x is None else f'{x:,.3f}' if isinstance(x,float) else str(x)
newq=q['new_content_quality']; formats=e['formats']; stop=s['stop'] or {}
partition_rows=[]
for key,v in coverage.items():
    if v['pass']!=1: continue
    replay=coverage.get(key.split('_')[0]+'_2',{})
    query=v['query']; r=v['result']
    partition_rows.append(f"|{query['section']}|{query['from']}–{query['to']}|{r['total']}|{len(v['unique_ids'])}|{r['pages']}|{'complete' if r['complete'] else 'INCOMPLETE: page cap'}|{replay.get('stable_order_and_total')}|")
stage_rows=[f"|{v['stage']}|{v['actual_cumulative_new']}/{v['target_cumulative_new']}|{v['new_documents']}|{v['new_contents']}|{v['verified_contents_delta']} / {v['needs_review_contents_delta']}|{v['selected_source_ids']}|{v['skipped_aliases']}|{v['technical_failures']}|{v['http_requests_import_only']}|{n(v['duration_seconds_run_start_to_finish_sum'])}|" for v in stages]
mapping_rows=[f"|{v['query']['section']}|{v['enumerated_unique_source_ids']}|{v['mapped_to_stored_canonical_source_ids']}|{len(v['unmapped_enumerated_ids'])}|{v['stored_canonical_groups_in_enumerated_ids']}|{v['not_enumerated_source_records']}|" for v in partition_stats]
run_rows=[f"|{v['run_id']}|{v['kind']}|{v['selected_source_ids']}|{v['new_canonical_documents']}|{v['already_existing_canonical_documents']}|{v['skipped_aliases']}|{v['new_contents']}|{v['http_requests']}|{v['technical_failures']}|" for v in runs]
projection_rows=[f"|{target}|{n(v['same_mix_hours'])}|{n(v['planning_range_hours_1x_to_3x'][1])}|{v['same_mix_requests']}|+{n(v['ocr_sensitivity_extra_hours_per_10_percent_at_60s_per_doc'])}h|" for target,v in p['projections'].items()]
problem_rows=[f"|[{v['url'].split('/')[-1]}]({v['url']})|{v['status']}|{(v['reason'] or '').replace('|','/').replace(chr(10),' ')[:700]}|{v.get('final_canonical_document_id') or 'unassociated'}|" for v in e['unassociated_review_items']]
report=f'''# P5 — Corpus Collection Pilot

Pilot 2026-10-28da Asia/Tashkent bo‘yicha boshlandi. Laravel13.39.0 / PHP8.5.11 / PostgreSQL17.11. Hisobot faqat yozilgan real evidencega asoslangan; yangi parser/OCR/AI,1000/full crawl,scheduling yoki deployment bajarilmadi.

## A. Executive summary

**{s['recommendation']} (keyingi1000pilot uchun)** — {s['new_canonical_documents']} NEW canonical hujjat, jami **{s['total_documents']}document/{s['total_contents']}content**. Quality: **{s['quality_counts'].get('verified',0)}verified/{s['quality_counts'].get('needs_review',0)}needs_review**. Yangi matnlar:{s['new_content_representations']}, updated hash representations:{s['updated_content_representations']}, unchanged full-text attempts:{s['unchanged_representation_attempts']} (preceding-run snapshot basis,alias skip excluded).

**{s['http_requests']}/{h['budget']} real HTTP request**, controller {n(s['controller_seconds'])}s, active worker {n(p['active_worker_seconds'])}s. Technical failed item:{s['technical_failed_items']}; yangi failed job:{s['new_failed_jobs']}; historical24saqlandi. Stop: `{stop.get('reason')}`, {stop.get('at')}. 360distinct source-ID cap tugadi; HTTPbudget tugamadi ({h['budget']-s['http_requests']}request qoldi). 200targetdan {200-s['new_canonical_documents']}NEW canonical yetishmadi; xavfsizlik guardi oshirilmadi. Pending jobs:{s['pending_jobs']}, pending items:{s['pending_items']}. Targetga yetilmagan bo‘lsa haqiqiy stop sababi shu; source-ID soni canonical success deb berilmadi.

[Machine summary](p5/final_summary.json), [live terminal](p5/live_terminal.txt), [request evidence](p5/requests.jsonl). Bounded runlar strict global completion emas, completed_with_errors sifatida qolishi mumkin; bu technical job failure bilan teng emas. Recommendation yangi1000runni boshlash ruxsati emas.

## B. Initial database state / Stage A

|Ko‘rsatkich|Before|After|
|---|---:|---:|
|documents|{b['rows']['documents']}|{f['rows']['documents']}|
|document_identifiers|{b['rows']['document_identifiers']}|{f['rows']['document_identifiers']}|
|document_contents|{b['rows']['document_contents']}|{f['rows']['document_contents']}|
|verified contents|{b['quality_counts']['verified']}|{f['quality_counts'].get('verified',0)}|
|needs_review contents|{b['quality_counts']['needs_review']}|{f['quality_counts'].get('needs_review',0)}|
|scrape_runs|{b['rows']['scrape_runs']}|{f['rows']['scrape_runs']}|
|scrape_run_items|{b['rows']['scrape_run_items']}|{f['rows']['scrape_run_items']}|
|failed_jobs|{b['rows']['failed_jobs']}|{f['rows']['failed_jobs']}|
|jobs|{b['rows']['jobs']}|{f['rows']['jobs']}|
|migrations|{b['rows']['migrations']}|{f['rows']['migrations']}|

Actual fixeddevelopment `mening_fikrim_active`, isolatedtests `mening_fikrim_active_test`. Reset/truncate/drop-development yo‘q. Baseline130tests/581assertions PASS; final{tests['tests']}tests/{tests['assertions']}assertions PASS, Pint PASS, P0offline16/P0.1offline37PASS. Queue/cache database; timeout900<lease930<retry_after960,1worker,>=2000ms, live/scheduler defaultfalse, scheduled tasks: 0. Jami 9 migration applied; **P5migration/schema change0**.

Earlier isolated test-DB restore command suite teardown bilan overlap qilib1migration error berdi. Development o‘zgarmadi; xato [log](p5/test_restore_race.txt)da saqlandi, sequential full rerun green, so‘ng test schema/2source seed tiklandi. Xato tuzatilgach yakuniy {tests['tests']}/{tests['assertions']} rerun muvaffaqiyatli o‘tdi. [Baseline](p5/database_baseline.json), [preflight](p5/stage_a_results.json), [tests](p5/final_tests.txt), [JUnit](p5/final_junit.xml), [formatter](p5/pint.txt).

## C. Implementation and stage results

Existing discovery→shared HTTP→HTML/PDF/Tesseract extraction→universal importer→content quality/persistence/protected candidate→durable run-item flow saqlandi. Source adapter business writes qilmaydi; new PilotCampaign faqat mavjud database-cache coordination va run checkpoint campaign IDdan foydalanadi. Legal status/quality/parser/identity qoidalari yumshatilmadi. O‘zgargan9PHP/testfayl:[inventory](p5/changed_files.json); asosiy qo‘shimchalar PilotCampaign, PilotStoppedException va documents:pilot CLI.

Finite4000campaign request cap:200discovery/replay+3000document/alias+600retry/redirect+200control/targeting planning allowance. Bu kafolatlangan cost emas; hard ceiling4000. Run1..30va400cap saqlandi. Har discovery/card/PDF/retry/redirect umumiy HTTP lock ichida budgetdan **request oldidan** joy oladi. Per-run context o‘zgarishi cumulative budgetni tiklamaydi; cacheforever state no24hexpiry.403/429darhol,2consecutive5xx/network error terminal stop; keyingi queue receipt olinmasdan worker chiqadi. Stopped campaign uchun reset yoki auto-resume command yo‘q. Cacheclear qilmang. Existing receipt original campaign state bo‘lmasa networkka o‘tmaydi.

New canonical count: baseline max document IDdan keyingi Lex source rows; universal identity/importer/FK/UNIQUE saqlandi. Bir source-ID job ko‘pi bilan bitta document yaratadi; batch source-ID limit remaining stage allowance’dan oshmaydi. Stage advance exact30/100new count,empty queue va controller integrity auditdan keyin. Driver source-ID selection ham 360 bilan bounded. Bu preflightda qo‘shilgan ikkinchi safety limit; talabdagi 200 canonical targetni source IDga almashtirmaydi. Nominal allocation 360/200=1.8 ID/target, real yield esa 360/158=2.278 ID/new canonical bo‘ldi. HTTP budget yetarli qolgan bo‘lsa ham controller shu oldindan yozilgan guardda to‘xtadi. Bu planning cheklovi keyingi campaign OLDIDAN qayta hisoblanishi kerak; joriy guard avtomatik oshirilmadi.

|Stage|Cumulative new / target|New docs|New contents|Verified / review delta|Selected IDs|Alias skips|Technical failed|Import HTTP|Run elapsed sum s|
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
{chr(10).join(stage_rows) or '|No completed stage snapshot||||||||||'}

Import HTTP discoveryni qamramaydi; umumiy{s['http_requests']}request ichida barcha discovery/replay overhead ham bor. Stage before/after snapshots va final integrity fayllari alohida. Completed bo‘lmagan stage completed deb olinmaydi.

|Run|Kind|Source IDs|New canonical|Already existing in run|Alias skips|New contents|HTTP|Technical failed|
|---|---|---:|---:|---:|---:|---:|---:|---:|
{chr(10).join(run_rows)}

Discovered unique source IDs:{s['discovered_unique_source_ids']}; selected source-ID attempts: {s['selected_source_id_attempts']}({s['distinct_selected_source_ids']}distinct). Stored canonical groups:{s['canonical_groups_stored']}; baseline-existing canonical discovered:{s['baseline_existing_canonical_documents_discovered']}({s['baseline_existing_listing_source_ids']}listing IDs), touched:{s['baseline_existing_canonical_documents_touched']}. Same-campaign repeatlar per-run existing sifatida alohida. Identity proven ammo unsaved groups:{s['proven_unpersisted_groups']}; unassociated review items:{s['unassociated_review_items']}. Alias skip:{s['skipped_alias_items']}; queue oldidan known identifier sifatida chiqarilgan listing IDs:{s['known_identifier_ids_excluded_before_queue']}. New identifier rows:{s['new_identifiers']}; identifier/language rowlar new-document targetga qo‘shilmadi.

## D. Discovery coverage

Actual nat/loc/int/tech/court/oliy rootlar, statusY(active+pending), **til filtrisiz**, inclusive sourcefrom/to. Har partition fresh session bilan2marta, current ASP.NET hidden-state POST pagination,8page cap. Source total, unique IDs,end termination,prefix/order va replay dalili saqlandi. Candidate order: partitionlar bo‘ylab round-robin, mavjud identifier/oldin attempted IDlarni chiqarish; identity hali faqat scraper/importerda tasdiqlanadi.

|Root|Date partition|Listing total|Unique IDs|Pages|Coverage|Replay stable|
|---|---|---:|---:|---:|---|---|
{chr(10).join(partition_rows)}

Nat2010partition qo‘shimcha1pageda to‘xtatildi, prefix diskka yozildi, fresh sessionda qayta yurildi; oldprefix20IDtakror ko‘rildi, full72ID/total/end qayta mos keldi ([resume proof](p5/discovery_resume.json)). Bu real shared scraper replay testi; durable queue/checkpoint crash regressiyalari offline PGsuite’da ham bor. Haqiqiy server tokenexpiry kuzatilmadi. Incomplete oliypartition to‘liq deb belgilanmadi; source countcanonical count emas. **Global Lex.uz coverage isbotlanmadi.** [Coverage JSON](p5/discovery_coverage.json), [sampling pool](p5/sampling_pool.json).

Enumerated listing IDsning final corpusdagi mapping holati (canonical soni observed IDs uchun,globaltotal uchun emas;bo‘limlararo canonicaloverlap sabab group countlar yig‘ilmaydi):

|Root|Enumerated sourceIDs|Mapped IDs|Unmapped IDs|Stored canonical groups|Beyond pagecap source records|
|---|---:|---:|---:|---:|---:|
{chr(10).join(mapping_rows)}

[Partition mapping audit](p5/discovery_partition_statistics.json). Bounded import source listing to‘liq enumeration bilan bir xil tushuncha emas; unmapped IDs/further pages ochiq qamrovdir.

## E. Extraction and quality

Unique persisted representations observed:{e['unique_persisted_representations']}; HTML:{formats.get('html',0)}, physicalPDF:{e['physical_pdf_representations']}(rawformat pdf={formats.get('pdf',0)},ocr={formats.get('ocr',0)}), text-layerPDF:{e['text_pdf_representations']}, OCR representations:{e['ocr_representations']}/{e['ocr_pages']}pages. Format va chars har real representationattempt uchun [extraction_statistics](p5/extraction_statistics.json)da; alias skip va metadata-only extraction attempt deb olinmagan.

Yangi content languages:`{json.dumps(e['new_content_languages'],ensure_ascii=False)}`. Newly persisted review:{q['new_review_content_persisted']}; newly verified:{newq.get('verified',0)}. Per-fulltext-attempt persistence success:{n(q['representation_persistence_success_rate']*100 if q['representation_persistence_success_rate'] is not None else None)}%; new-content automated verification rate:{n(q['new_verified_rate']*100 if q['new_verified_rate'] is not None else None)}%. Denominatorlar boshqa: metadata/identity rejectionlar persistence denominatoriga yashirin qo‘shilmadi, yuqorida/alohida URLsda ko‘rsatilgan.

Extraction-failed items:{q['extraction_failure_items']}; failed language representations:{q['failed_representations']}; technical failed items:{s['technical_failed_items']}. Empty fake content row yo‘q. Usable needs_review text saqlanadi, original reason codes JSONBda qoladi. Verified inson/yuridik certification emas. New review issues:`{json.dumps(q['new_content_issue_counts'],ensure_ascii=False)}`.

{'Real scanned PDF/OCR uchramadi; P5 OCR throughput/accuracy success da’vosi yo‘q. P0–P4 OCR regressiyalari o‘tdi.' if not e['ocr_representations'] else 'Real OCR natijalari page/timing/issues bilan evidence faylida; confidence document certification emas.'}
{'P5 sample PDFni ham qamramadi; PDF extraction bo‘yicha P5 live coverage gap ochiq. Existing PDFparser/regression saqlandi.' if not formats.get('pdf') else 'PDF strukturasi/order/language uncertaintylari reviewligicha; yangi PDF extraction technology yo‘q.'}

Unassociated/problematic items (har biri durable run-itemda; muvaffaqiyat deb berilmadi):

|URL|Outcome|Sabab|Final canonical mapping|
|---|---|---|---|
{chr(10).join(problem_rows) or '|—|0|No unassociated review/failed item|—|'}

Real format examples:

|Source|Format|Pages|Language|Stored chars|Quality|Parse / OCR seconds|
|---|---|---:|---|---:|---|---:|
|[8345596](https://lex.uz/uz/docs/8345596)|text-layer PDF|34|uz-Latn|57197|needs_review|6.743 /0|
|[4797522](https://lex.uz/uz/docs/4797522)|natural scanned PDF / existing Tesseract|7|uz-Cyrl|7316|needs_review|21.030 /20.513|

Bu ikki realPDFsample corpusformat distribution yokiOCRaccuracycertification emas. Low confidence/critical-token/order/language/image warnings saqlandi; matn verifiedga ko‘tarilmadi. New legal status:`{json.dumps(storage['legal_statuses_new'],ensure_ascii=False)}`; source document type counts:`{json.dumps(storage['document_types_new'],ensure_ascii=False)}`. Legal status quality enumdan mustaqil.

8unassociated review **item**dan{s['review_items_later_resolved_by_alias']}tasi keyin boshqa proven alias orqali saqlangan canonicalga bog‘langan (jadval finalmapping). Qolgan attemptedsourceIDs5ta; bu5canonicalhujjat degani emas. Bundan tashqari7listingIDcap tufayli tanlanmagan. UnknownRuscha/Русча labels va body-requisite/nonreciprocal-link ziddiyatlarini guardni yumshatib taxminan mergeqilmadik. Sourcecaption/rawcardni bu pilotdan keyin qayta fetchqilmadik; keyingi fixture-backedfixlar alohida boundedtrafficqarori bilan.

## F. Performance

|Metric|Observed|
|---|---:|
|Controller end-to-end s|{n(p['controller_elapsed_seconds'])}|
|Active worker s|{n(p['active_worker_seconds'])}|
|Operator pause in controller s|0|
|Shared pacing/backoff wait s|{n(p['network_pacing_and_backoff_wait_seconds'])}|
|HTTP request/response time s|{n(h['http_seconds'])}|
|New canonical/h worker / end-to-end|{n(p['new_documents_per_hour_worker'])} / {n(p['new_documents_per_hour_end_to_end'])}|
|New content/h worker / end-to-end|{n(p['new_contents_per_hour_worker'])} / {n(p['new_contents_per_hour_end_to_end'])}|
|Requests/newcanonical incl discovery/repeat/alias|{n(p['requests_per_new_document_all_overhead'])}|
|HTTP / queue retry attempts|{h['retries']} / {p['queue_retry_attempts']}|
|Minimum request start interval s|{n(h['min_interval_seconds'])}|
|PHP peak MiB|{n(p['peak_php_bytes']/1024**2)}|
|Max self / child RSS MiB|{n(p['max_self_rss_kib']/1024)} / {n(p['max_child_rss_kib']/1024)}|
|HTML parse p50/p95 s|{n(p['html_parse_seconds']['p50'])} / {n(p['html_parse_seconds']['p95'])}|
|PDF parse p50/p95 s|{n(p['pdf_parse_seconds']['p50'])} / {n(p['pdf_parse_seconds']['p95'])}|
|Measured OCR total s|{n(p['ocr_seconds']['sum'])} ({p['ocr_seconds']['observations']}observations)|
|Queue wait p50/p95 s|{n(p['queue_wait_seconds']['p50'])} / {n(p['queue_wait_seconds']['p95'])}|
|DB transaction+reconciliation estimate s|{n(p['transaction_and_reconciliation_seconds_estimate'])}|
|All worker DB query seconds|{n(p['all_worker_database_query_seconds'])}|

Worker time pacing/network/extraction/DBni o‘z ichiga oladi. Controller minus worker:discovery,replay,audit,queue orchestration,fileI/O; operator pauzasi emas. Pacing/HTTP/worker vaqtlarini bir-biriga qayta qo‘shmang. Queue waiting percentillari itemlar bo‘ylab, wallga additive emas. DB transaction estimate fetch_parse_import−fetch_parse farqi, faqatwrite-only exactmeter emas. RSSpeaklar individual, yig‘indi simultaneousRAM emas. [Performance](p5/performance.json), [worker profiles](p5/worker_profiles.json), [HTTP](p5/http_statistics.json). Client start intervalining aniq minimumi **{h['min_interval_seconds']:.9f}s**. Joriy storage o‘lchovlari:[storage statistics](p5/storage_statistics.json); physical baseline size olinmagani uchun physical growth o‘lchangan deb ko‘rsatilmaydi.

Planning projections (ruxsat yoki kafolat emas):

|Documents|Same observed mix hours|3×planning hours|Requests incl observed overhead|10%docs×assumed60sOCR sensitivity|
|---:|---:|---:|---:|---:|
{chr(10).join(projection_rows)}

Same-mix linear scaling observed discovery/alias/repeat/retry overheadni saqlaydi.1×–3×planning scenario confidence interval emas; yangi date-partition discovery,OCRdistribution,large tables,access stops,source changes yoki100000scope pagination xarajati boshqacha bo‘lishi mumkin.60sOCRsensitivlik **taxmin**, real benchmark emas.0observedretry kelajakda0retry kafolati emas. Bu sample source access/1000/full import capacityni tasdiqlamaydi.

## G. Integrity, resume and data protection

Final audit errors:`{json.dumps(f['errors'])}`; duplicate language/alias/orphan counts:`{json.dumps(f['checks'])}`. Original29canonical anchors,82identifier associations,63content IDs/languages/hashes/quality va24failed-jobrowhashsaqlandi. Verified content avtomatik needs_review bilan almashtirilmadi. P5da bunday conflicting live candidate uchramadi; himoya P4/P5 realPG regressionlari va originalhash auditlari bilan tekshirildi; P4candidate staging/shrink/stale policy va realPGprotection regressiyalari saqlandi. Har batch/stage snapshotdalili mavjud; fake/empty text yo‘q, legal status independent.

Worker6jobdan keyin betweentasks to‘xtadi:5completed item,15unfinished queue receipt; explicitresume receipt IDs/payloadlarini o‘zgartirmadi va restart yakunladi ([proof](p5/interruption_resume.json)).3group repeat document/content counts va alltext hashes stable ([proof](p5/repeat_import.json)). Bu graceful restart; hard kill/crash,lease/retry/backoff/tokenfencing realPGoffline regressiyalarida, P5devdata corruption bilan simulyatsiya qilinmadi.

Historical P0–P4protected artifacts:{hist['protected_files']}, changed:{len(hist['changed'])}. Yangi table, migration yoki extraction engine qo‘shilmadi; mavjud Tesseract real7pagefallbackda ishladi. No verified downgrade or deletion. Database-cache state finite pilot coordination; cacheeviction/resetdan guard failclosed bo‘lsa ham independent archival registry emas. [Integrity](p5/integrity_audit.json), [original row preservation](p5/preservation.json), [final environment/test-schema checks](p5/final_checks.json), [historical audit](p5/historical_integrity.json).

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
php artisan documents:sync lex --report={progress_run if (progress_run:=runs[-1]['run_id'] if runs else None) else 11}
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

Recommendation:**{s['recommendation']} keyingi1000uchun**; controlledcollection safety/integrity o‘tdi,exact200gate ochiq. Avval qolgan42NEWcanonical uchun alohida tasdiqlanadigan bounded supplementation: observed360/158=2.278source-ID/newcanonical sabab sourcecapni kampaniyadan OLDIN qayta hisoblash kerak (same-yield200uchun taxmin456sourceID, kafolat emas);360guardmidcampaign oshirilmadi. Keyingi1000pilotdan oldin incomplete datepartitionlarni bo‘lish,PDF/OCRtargetedcoverage va manualQA,sourceaccess/storagelongrunshartlarini ko‘rib chiqing. **1000pilot/fullcrawl/scheduler avtomatik boshlanmadi.**
'''
(D/'P5_REPORT.md').write_text(report)
