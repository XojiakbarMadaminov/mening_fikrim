# P3 — Scraping Performance Benchmark

2026-10-28, Asia/Tashkent. **Live tavsiya: 1 worker / kamida 2000 ms**, o'zgartirilmagan. 2 worker va 1000 ms natijalari faqat offline; live tezlik yoki access ruxsati emas.

## Offline muhit va workload

Haqiqiy local PostgreSQL `mening_fikrim_active_test`, Laravel Database Queue, actual shared database-cache HTTP pacer. Har variantda 6 ta yangi controlled identity: 3 valid HTML, 1 image-review HTML, 2 uncertified OCR review. Har birida 2 fake HTTP request, jami 12; fake transportga ataylab 20 ms latency berilgan. Tesseract asl binary/model bilan 2000×2800 px controlled Cyrillic image'da ishladi, OMP_THREAD_LIMIT=1. Source hujjatlar emas, synthetic benchmark. Raw fixture image vaqtinchalik.

Barcha importlar workerlar boshlanishidan oldin queuega qo'yildi. Ikkala worker ham ishlagani per-process transaction profile bilan tekshirildi. Har variant 3 created, 3 durable review, 0 technical failure; review failed_jobsga tushmadi. Bitta o'lchov/variant — statistical confidence interval yoki corpus SLA emas.

## Throughput va common pacing

| Worker | Interval | Wall s | Req/s | Valid/h | Review/h | Eng kichik gap s | Accepted Markdown char/s |
|---:|---:|---:|---:|---:|---:|---:|---:|
| 1 | 2000 ms | 35.192 | 0.341 | 306.9 | 306.9 | 2.000066 | 136.6 |
| 2 | 2000 ms | 30.340 | 0.396 | 356.0 | 356.0 | 2.000022 | 158.4 |
| 1 | 1000 ms | 25.199 | 0.476 | 428.6 | 428.6 | 1.000066 | 190.7 |
| 2 | 1000 ms | 19.294 | 0.622 | 559.8 | 559.8 | 1.000066 | 249.1 |

Valid/h va review/h ushbu qisqa fixture natijasining soatga chiziqli normallanishi. Haqiqiy hujjatlar/soat prognozi emas. Shared 2000 ms bilan 2 worker valid throughputni taxminan 16% oshirdi; tarmoq budgeti ikki baravar bo'lmadi. 1000 ms faqat Test namespace'dagi subclass orqali, `Http::fake` + `preventStrayRequests`dan keyin simulyatsiya qilindi; production client floor 2000 ms.

## Latency, queue va parser

| Worker / ms | Fake HTTP P50/P95 ms | Queue wait P50/P95 s | HTML parse P50/P95 ms | OCR total s | Retry rate |
|---|---:|---:|---:|---:|---:|
| 1 / 2000 | 26.73 / 43.34 | 7.321 / 26.101 | 0.558 / 6.358 | 13.551 | 0% |
| 2 / 2000 | 26.75 / 46.10 | 2.605 / 18.569 | 0.581 / 6.327 | 13.577 | 0% |
| 1 / 1000 | 26.61 / 43.95 | 3.973 / 16.744 | 0.565 / 6.883 | 13.553 | 0% |
| 2 / 1000 | 26.86 / 46.84 | 2.256 / 8.248 | 0.605 / 6.337 | 13.581 | 0% |

P50/P95 nearest-rank, kichik N: 12 request, 6 item, 4 HTML parse. Latency so'rov boshlangandan responsegacha; pacer kutishi HTTP latencyga kirmaydi, queue/wall vaqtga kiradi. OCR yig'indisi parallel holatda ham task vaqtlarining yig'indisi. Ushbu fixture benchmarkda PDF render/extraction yo'q; PDF parse time bu jadvalda **o'lchanmagan**. Real PDF diagnostikasi P3_REPORT va live item timingsda alohida.

## CPU/RAM va PostgreSQL

| Worker / ms | CPU self+children s | Wallga nisbatan CPU % | Max worker RSS MiB | Max child RSS MiB | Source lock query P95 ms | Transaction P95 ms |
|---|---:|---:|---:|---:|---:|---:|
| 1 / 2000 | 14.238 | 40.5 | 105.6 | 135.5 | 0.740 | 18.598 |
| 2 / 2000 | 14.583 | 48.1 | 105.9 | 135.3 | 0.660 | 24.424 |
| 1 / 1000 | 14.240 | 56.5 | 105.3 | 135.1 | 0.900 | 15.711 |
| 2 / 1000 | 14.567 | 75.5 | 105.9 | 135.5 | 0.740 | 20.770 |

CPU Linux getrusage self+children, process bootstrap ham kiradi. RSS per-process high-water; jami tizim RAMi yoki bir vaqtdagi process RSS yig'indisi emas. Source `FOR UPDATE` query vaqti execution+waitni o'z ichiga oladi, sof lock wait deb ko'rsatilmaydi. 100 ms `pg_stat_activity` samplingda Lock wait topilmadi — sub-100 ms kutish yo'q degani emas. P1 source lock va yangi P3 item race testida ikki haqiqiy PostgreSQL waiter alohida isbotlangan. HTTP/OCR vaqtida source importer transactioni ochiq emas; bu regression bilan tekshirildi.

## Alohida OCR contention

Bir xil controlled image'da 2 task: ketma-ket **13.674 s**, ikki parallel processda **6.805 s**. Har process peak RSS taxminan 135–136 MiB, CPU har task uchun taxminan 6.8 s. Bu hostda OCR CPU qismini parallel ishlash mumkinligini ko'rsatadi; uning natijasi hujjat accuracy yoki Lex.uz throughputi emas. Barcha OCR candidate'lar certified=false va review.

## Alohida live o'lchov

P3 real kampaniyasi: **289 request, 289×200, minimal start interval 2.000021697 s**, HTTP P50/P95 **88.779/781.670 ms**, retry 0. Birinchi/oxirgi request oralig'i 17:57:25–18:38:50 (+05); ichida operator tanaffusi, OCR va qayta testlar bor. Bu oraliq uzluksiz worker throughputi emas.

Yakuniy run 7: **193 s, 90 request, 0.466 req/s**, 19 source ID/16 canonical group. 3 mavjud valid group qayta tasdiqlandi, 13 review, 3 alias skip; yangi create/update 0. Chiziqli normallashgan tezlik **55.96 valid recheck/h**, **242.49 review/h**; bu yangi hujjat create/h yoki corpus SLA emas. 9 valid til matni qayta tekshirildi. HTML 36 parse variantida P50/P95 **12.819/873.918 ms**, 6 PDFda **2.725/3.724 s**. PDF page parsing va optional layout diagnostikasi shu PDF vaqtiga kiradi; main run OCR ishlatmagan.

Real alternate scan PDFlar alohida: 17+19 sahifa, birinchi extraction OCR **71.346+63.106=134.451 s**, PDF processing jami **152.158 s**. Region diagnostikasi uchun ayni baytlarning offline qayta extractioni yana 36 page execution; yangi 36 unique source page deb sanalmaydi. Accuracy natijalari [P3_REPORT](P3_REPORT.md)da.

Yakuniy worker profile: wall **193.660 s**, CPU self+children **29.893 s**, PHP peak allocation **72.5 MiB**, worker peak RSS **221.67 MiB**, child peak RSS **217.57 MiB**. RSS process high-water, jami concurrent host RAMi emas. Live driver source-lock timingni instrument qilmagan; offline SQL o'lchovini live deb ko'rsatmaymiz. Dalillar: [HTTP](p3/http_stats.json), [run summary](p3/final_repeat_summary.txt), [live metrics](p3/live_performance.json), [worker profile](p3/worker_profile_183851.json).

## Chegaralar va tavsiya

- Live: **1 worker / 2000 ms**, current policy saqlanadi. 403/429 kuzatilmasa ham intervalni kamaytirish tasdiqlanmaydi.
- Keyingi alohida ruxsatli muhitda 2 worker / shared 2000 ms CPU overlap uchun nomzod. Hozir live 2 worker ishga tushirilmadi.
- 1000 ms live uchun tavsiya etilmaydi: serverning ruxsat etilgan tezligi noma'lum. Offline tezlashuv buni isbotlamaydi.
- Asosiy bottleneck — quality. 3/6 review bo'lgan workload tezligi valid corpus importiga teng emas. PDF table/reading order/language va OCR certificationga e'tibor kerak.
- 6 synthetic item va bitta run/variant bo'yicha 1000/full readiness tasdiqlanmaydi. IO/disk pressure, OS hard memory limit va uzoq muddatli fairness keyingi gate'larga qoladi.

Dastlabki benchmarkda ikkinchi `--stop-when-empty` worker yagona discovery job band bo'lganida chiqib ketgan. Bu natijalar `p3/benchmark_initial_unbalanced.json`da saqlandi, haqiqiy two-worker taqqoslashga ishlatilmadi. Import queue oldindan tayyorlangach barcha 4 variant yangi synthetic IDlar bilan qayta o'lchandi.

Dalillar: [raw results](p3/benchmark_results.json), [summary](p3/benchmark_summary.json), [terminal](p3/benchmark_terminal.txt), [OCR profiles](p3/ocr_contention.json), [benchmark code](p3/benchmark.php). [Laravel Queue](https://laravel.com/framework/docs/13.x/queues) va [Symfony Process](https://symfony.com/doc/current/components/process.html) ishlatildi; raqamlar rasmiy dokumentatsiyadan emas, ushbu local o'lchovlardan olingan.
