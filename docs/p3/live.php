<?php

/** Manually invoked, capped P3 campaign. Never part of default tests or a schedule. */
use App\Models\DocumentSource;
use App\Models\ScrapeRun;
use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzParser;
use App\Scraping\LexUz\LexUzScraper;
use App\Scraping\LexUz\PdfQualityInspector;
use App\Scraping\LexUz\PdfTextExtractor;
use App\Services\Scraping\DiscoveryPlanner;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Symfony\Component\Console\Output\ConsoleOutput;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() AS name')->name !== 'mening_fikrim_active' || ! config('scraping.live_enabled') || config('scraping.workers') !== 1 || config('scraping.request_delay_ms') < 2000) {
    throw new RuntimeException('P3 live driver requires fixed development DB, explicit live enabled, one worker and >=2000 ms.');
}
$temp = getenv('P3_LIVE_TMP');
if (! $temp || ! is_dir($temp) || ! str_starts_with($temp, '/tmp/lex-p3-live-')) {
    throw new RuntimeException('Provide a private P3_LIVE_TMP.');
}
$save = fn ($name, $value) => file_put_contents(__DIR__.'/'.$name.'.json', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
Event::listen(ResponseReceived::class, function ($event) use ($temp): void {
    if (preg_match('~/actinfo/card1/(8554432|8554353)$~', parse_url($event->request->url(), PHP_URL_PATH) ?? '', $match)) {
        file_put_contents($temp.'/'.$match[1].'.card.'.hrtime(true).'.html', $event->response->body());
    }
    if (str_contains(strtolower($event->response->header('Content-Type')), 'application/pdf') && str_starts_with($event->response->body(), '%PDF-')) {
        $hash = hash('sha256', $event->response->body());
        file_put_contents($temp.'/'.$hash.'.pdf', $event->response->body());
        file_put_contents($temp.'/pdf_index.jsonl', json_encode(['url' => $event->request->url(), 'sha256' => $hash, 'bytes' => strlen($event->response->body())])."\n", FILE_APPEND);
    }
});
// A single 400-request campaign budget spans all explicit runs/probes in this driver.
$http = new class extends LexHttpClient
{
    public function context(string $context): void
    {
        parent::context('p3-live-campaign');
    }
};
$http->context('p3-live-campaign');
$app->instance(LexHttpClient::class, $http);
$real = app(LexUzScraper::class);
$app->instance(DocumentScraper::class, new class($real, $temp) implements DocumentScraper
{
    public function __construct(private LexUzScraper $real, private string $temp) {}

    public function discover(array $segment, callable $onPage, int $pageBudget): array
    {
        return $this->real->discover($segment, $onPage, $pageBudget);
    }

    public function fetch(string $externalId, ?string $listingStatus = null): array
    {
        $result = $this->real->fetch($externalId, $listingStatus);
        file_put_contents($this->temp.'/'.$externalId.'.candidate.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $result;
    }
});
$manager = app(ScrapeRunManager::class);
$action = $argv[1] ?? '';
if ($action === 'dispatch') {
    $ids = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    // Conservative source-ID campaign cap is even tighter than the 30 canonical-group cap.
    $testedFile = $temp.'/tested_ids.json';
    $tested = is_file($testedFile) ? json_decode(file_get_contents($testedFile), true, flags: JSON_THROW_ON_ERROR) : [];
    $union = array_values(array_unique([...$tested, ...$ids]));
    if (count($union) > 30) {
        throw new RuntimeException('Campaign would exceed 30 selected source entrypoints.');
    }
    $run = $manager->start(DocumentSource::where('slug', 'lex')->firstOrFail(), 'full', count($ids), $ids);
    $listing = json_decode(file_get_contents(__DIR__.'/discovery_roots.json'), true, flags: JSON_THROW_ON_ERROR);
    $statuses = [];
    foreach (DiscoveryPlanner::ROOTS as $root) {
        foreach ($listing[$root]['rows'] as $row) {
            if (in_array($row['id'], $ids, true)) {
                $statuses[$row['id']] = $row['listing_status'];
            }
        }
    }
    if (is_file(__DIR__.'/pending_discovery.json')) {
        foreach (json_decode(file_get_contents(__DIR__.'/pending_discovery.json'), true)['rows'] as $row) {
            if (in_array($row['id'], $ids, true)) {
                $statuses[$row['id']] = $row['listing_status'];
            }
        }
    }
    $cp = $run->checkpoint;
    $cp['sample_listing_statuses'] = $statuses;
    $run->update(['checkpoint' => $cp]);
    file_put_contents($testedFile, json_encode($union));
    echo 'P3 bounded run '.$run->id.' selected '.count($ids).' source IDs; conservative campaign total '.count($union)."\n";
} elseif ($action === 'worker') {
    $start = hrtime(true);
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--tries' => 3, '--timeout' => 900, '--memory' => 512, '--sleep' => 1, '--stop-when-empty' => true, '--max-time' => 1800, '--max-jobs' => (int) ($argv[2] ?? 100)], new ConsoleOutput);
    $save('worker_profile_'.date('His'), ['wall_seconds' => (hrtime(true) - $start) / 1e9, 'php_peak_bytes' => memory_get_peak_usage(true), 'self_usage' => getrusage(), 'child_usage' => getrusage(1)]);
} elseif ($action === 'snapshot') {
    $run = ScrapeRun::findOrFail((int) $argv[2]);
    $manager->reconcile($run->id);
    $run->refresh();
    $save('run_'.$run->id, ['run' => $run->toArray(), 'summary' => $manager->summary($run), 'items' => $run->items()->orderBy('id')->get()->toArray(),
        'rows' => collect(['documents', 'document_identifiers', 'document_contents', 'scrape_runs', 'scrape_run_items', 'jobs', 'failed_jobs'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all(),
        'contents' => DB::table('document_contents')->orderBy('id')->get(['id', 'document_id', 'language', 'content_hash'])->toArray()]);
    echo json_encode($manager->summary($run), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
} elseif ($action === 'roots') {
    $roots = [];
    foreach (DiscoveryPlanner::ROOTS as $root) {
        $query = ['section' => $root, 'status' => 'Y'];
        $url = $real->searchUrl($query);
        $listing = $real->listing($http->get($url)->body());
        $roots[$root] = ['query' => $query, 'url' => $url, 'signature' => app(DiscoveryPlanner::class)->signature($query), 'total' => $listing['total'], 'rows' => $listing['rows'], 'observed_at' => now()->toIso8601String()];
        echo $root.' real first page total='.$listing['total']."\n";
        flush();
    }
    $save('discovery_roots', $roots);
} elseif ($action === 'nat-pages') {
    $pages = [];
    $query = ['section' => 'nat', 'status' => 'Y'];
    $result = $real->discover($query, function ($page) use (&$pages) {
        $pages[] = $page;

        return count($pages) < 2;
    }, 2);
    $roots = json_decode(file_get_contents(__DIR__.'/discovery_roots.json'), true, flags: JSON_THROW_ON_ERROR);
    $roots['nat'] = ['query' => $query, 'url' => $real->searchUrl($query), 'signature' => app(DiscoveryPlanner::class)->signature($query),
        'total' => $result['total'], 'rows' => array_merge(...array_column($pages, 'rows')), 'observed_at' => now()->toIso8601String(), 'pages_checked' => count($pages)];
    $save('discovery_roots', $roots);
    $save('nat_pagination', ['result' => $result, 'pages' => $pages]);
    echo 'Real nat pages checked='.count($pages).'; no global coverage claim.'."\n";
} elseif ($action === 'historical') {
    $query = ['section' => 'nat', 'status' => 'Y', 'from' => '01.01.2010', 'to' => '31.01.2010'];
    $pages = [];
    $result = $real->discover($query, function ($page) use (&$pages) {
        $pages[] = $page;

        return true;
    }, 8);
    $save('historical_discovery', ['query' => $query, 'signature' => app(DiscoveryPlanner::class)->signature($query), 'observed_at' => now()->toIso8601String(), 'result' => $result, 'pages' => $pages]);
    echo json_encode($result)."\n";
} elseif ($action === 'card-language') {
    $probes = [];
    foreach (['8554432', '8554353'] as $id) {
        $previous = json_decode(file_get_contents($temp.'/'.$id.'.candidate.json'), true, flags: JSON_THROW_ON_ERROR);
        $url = $previous['metadata']['lex']['metadata_url'];
        $response = $http->get($url);
        file_put_contents($temp.'/'.$id.'.card.html', $response->body());
        $card = app(LexUzParser::class)->card($response->body());
        $probes[$id] = ['url' => $url, 'previous_language' => $previous['metadata']['lex']['source_card']['Язык акта'] ?? null,
            'observed_language' => $card['Язык акта'] ?? null, 'sha256' => hash('sha256', $response->body()), 'observed_at' => now()->toIso8601String()];
        echo $id.' source language label: '.json_encode($card['Язык акта'] ?? null, JSON_UNESCAPED_UNICODE)."\n";
    }
    $save('card_language_probe', $probes);
} elseif ($action === 'pdf-regression') {
    $results = [];
    $gold = json_decode(file_get_contents(dirname(__DIR__).'/p0_1/ocr_gold.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (['-8553258', '-8553692'] as $id) {
        $sourceUrl = 'https://lex.uz/uz/docs/'.$id;
        $html = $http->get($sourceUrl)->body();
        if (! preg_match("/openUrl\\(['\"]([^'\"]*\/pdfs\/[^'\"]+)['\"]/u", $html, $match)) {
            $results[$id] = ['source_url' => $sourceUrl, 'available' => false, 'reason' => 'Current page has no source-discovered PDF download link.'];

            continue;
        }
        $viewerUrl = $http->absolute($match[1]);
        $viewer = $http->get($viewerUrl);
        $resource = app(LexUzParser::class)->pdfUrl($viewer->body());
        if ($resource === null) {
            $results[$id] = ['source_url' => $sourceUrl, 'viewer_url' => $viewerUrl, 'available' => false, 'reason' => 'Current source PDF viewer exposes no PDFObject resource.'];

            continue;
        }
        $response = $http->get($resource, pdf: true);
        $candidate = json_decode(file_get_contents($temp.'/'.$id.'.candidate.json'), true, flags: JSON_THROW_ON_ERROR);
        $row = $candidate['metadata']['lex']['source_card']['accepting_bodies'][0];
        $parsed = app(PdfTextExtractor::class)->extract($response->body(), $response->header('Content-Type'), 'uz-Latn', ['document_number' => $row[5], 'issued_date' => $row[4]]);
        file_put_contents($temp.'/'.$id.'.pdf-regression.md', $parsed['content']);
        $reference = array_values(array_filter($gold, fn ($g) => $g['id'] === $id))[0];
        $page = explode('<!-- page '.$reference['page'].' -->', $parsed['content'])[1] ?? '';
        $page = preg_replace('/\s+/u', ' ', explode('<!-- page ', $page)[0]);
        $pattern = $id === '-8553692' ? '/4[.,]?\s+Ушбу қарор.*?кучга киради[.]?/u' : '/39[.,]?\s+Ушбу Низом.*?жавоб беради[.]?/u';
        $matched = preg_match($pattern, $page, $excerpt);
        $results[$id] = ['source_url' => $sourceUrl, 'viewer_url' => $viewerUrl, 'resource_url' => $resource, 'available' => true,
            'role' => 'Alternate source-discovered PDF rendition; current main document is HTML. No OCR admission.',
            'quality' => $parsed['quality'], 'physical_language' => $parsed['language'],
            'gold' => $matched ? ['page' => $reference['page'], 'reference' => $reference['reference'], 'observed' => $excerpt[0],
                'metrics' => app(PdfQualityInspector::class)->compareGold($reference['reference'], $excerpt[0])] : ['matched' => false]];
        echo $id.' alternate PDF parsed: '.$parsed['quality']['format'].' / '.$parsed['quality']['pages_extracted']." pages\n";
        flush();
    }
    $save('alternate_pdf_regression', $results);
} elseif ($action === 'pending-search') {
    $segment = ['section' => 'nat', 'status' => 'Y', 'from_eff' => now()->addDay()->format('d.m.Y'), 'to_eff' => '31.12.2027'];
    $url = $real->searchUrl($segment);
    $page = $real->listing($http->get($url)->body());
    $save('pending_discovery', ['url' => $url, 'query' => $segment, 'signature' => app(DiscoveryPlanner::class)->signature($segment), 'total' => $page['total'], 'rows' => $page['rows'], 'observed_at' => now()->toIso8601String()]);
    echo 'Real pending-date listing returned '.$page['total'].' source records; only first '.count($page['rows'])." rows inspected.\n";
} else {
    throw new RuntimeException('Unknown live evidence action.');
}
