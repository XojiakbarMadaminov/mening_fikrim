<?php

/** Explicit P4 bounded verification only. No raw source capture, no scheduler, no default-test execution. */
use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentSource;
use App\Models\ScrapeRun;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzParser;
use App\Scraping\LexUz\LexUzScraper;
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
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active' || ! config('scraping.live_enabled') || config('scraping.scheduler_enabled') || config('scraping.workers') !== 1 || config('scraping.request_delay_ms') < 2000 || config('scraping.request_budget') !== 400) {
    throw new RuntimeException('Explicit bounded P4 live policy required: fixed dev DB, one worker, >=2000ms, budget400, scheduler off.');
}
config(['logging.channels.scraping.driver' => 'single', 'logging.channels.scraping.path' => __DIR__.'/requests.jsonl']);
$http = new class extends LexHttpClient
{
    public function context(string $context): void
    {
        parent::context('p4-corpus-campaign');
    }
};
$http->context('p4-corpus-campaign');
$app->instance(LexHttpClient::class, $http);
Event::listen(ResponseReceived::class, function ($event): void {
    if (! preg_match('~/actinfo/card1/(8548643|-8548637|8548637)$~', parse_url($event->request->url(), PHP_URL_PATH) ?? '')) {
        return;
    }
    try {
        $card = app(LexUzParser::class)->card($event->response->body());
        $record = ['url' => $event->request->url(), 'at' => now()->toAtomString(), 'response_sha256' => hash('sha256', $event->response->body()), 'card' => $card];
    } catch (Throwable $e) {
        $record = ['url' => $event->request->url(), 'error' => $e->getMessage()];
    }
    file_put_contents(__DIR__.'/control_card_observations.jsonl', json_encode($record, JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND);
});
$scraper = app(LexUzScraper::class);
$manager = app(ScrapeRunManager::class);
$save = fn ($name, $value) => file_put_contents(__DIR__.'/'.$name.'.json', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
$action = $argv[1] ?? '';
if ($action === 'listing') {
    $evidence = [];
    foreach (DiscoveryPlanner::ROOTS as $root) {
        $query = ['section' => $root, 'status' => 'Y'];
        $pages = [];
        $result = $scraper->discover($query, function ($page) use (&$pages, $root) {
            $pages[] = $page;

            return count($pages) < ($root === 'nat' ? 2 : 1);
        }, $root === 'nat' ? 2 : 1);
        $evidence[$root] = ['query' => $query, 'result' => $result, 'pages' => $pages];
        echo $root.' bounded listing total='.$result['total']."\n";
        flush();
    }
    $save('listing_evidence', $evidence);
} elseif ($action === 'dispatch') {
    if (DB::table('jobs')->exists()) {
        throw new RuntimeException('Existing queue must be empty before a P4 verification run.');
    }
    $ids = json_decode(file_get_contents(__DIR__.'/sample_ids.json'), true, flags: JSON_THROW_ON_ERROR);
    if (count($ids) > 30 || count($ids) !== count(array_unique($ids))) {
        throw new RuntimeException('Bounded unique sample required.');
    }
    $run = $manager->start(DocumentSource::where('slug', 'lex')->firstOrFail(), 'full', count($ids), $ids);
    $statuses = [];
    foreach (json_decode(file_get_contents(__DIR__.'/listing_evidence.json'), true) as $root) {
        foreach ($root['pages'] as $page) {
            foreach ($page['rows'] as $row) {
                if (in_array($row['id'], $ids, true)) {
                    $statuses[$row['id']] = $row['listing_status'];
                }
            }
        }
    }
    $checkpoint = $run->checkpoint;
    $checkpoint['sample_listing_statuses'] = $statuses;
    $run->update(['checkpoint' => $checkpoint]);
    echo 'P4 bounded run '.$run->id.' queued, '.count($ids)." source IDs.\n";
} elseif ($action === 'worker') {
    $started = hrtime(true);
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--tries' => 3, '--timeout' => 900, '--memory' => 512, '--sleep' => 1, '--stop-when-empty' => true, '--max-jobs' => 30, '--max-time' => 1800], new ConsoleOutput);
    $save('worker_profile_'.date('His'), ['wall_seconds' => (hrtime(true) - $started) / 1e9, 'php_peak_bytes' => memory_get_peak_usage(true), 'self_usage' => getrusage(), 'child_usage' => getrusage(1)]);
} elseif ($action === 'snapshot') {
    $run = ScrapeRun::findOrFail((int) $argv[2]);
    $manager->reconcile($run->id);
    $run->refresh();
    $contents = [];
    foreach (DocumentContent::orderBy('id')->cursor() as $content) {
        $contents[] = ['id' => $content->id, 'document_id' => $content->document_id, 'language' => $content->language, 'characters' => mb_strlen($content->content),
            'content_hash' => $content->content_hash, 'hash_matches' => hash('sha256', $content->content) === $content->content_hash,
            'quality_status' => $content->quality_status, 'quality_issues' => $content->quality_issues,
            'source_url' => $content->extraction_metadata['source_url'] ?? null, 'provenance' => $content->extraction_metadata['provenance'] ?? [],
            'pending_candidate_hash' => $content->pending_candidate['content_hash'] ?? null];
    }
    $save('run_'.$run->id, ['run' => $run->toArray(), 'summary' => $manager->summary($run), 'items' => $run->items()->orderBy('id')->get()->toArray(),
        'contents' => $contents, 'documents' => Document::orderBy('id')->get()->map(fn ($document) => ['id' => $document->id, 'external_id' => $document->external_id, 'status' => $document->status->value, 'quality_status' => $document->quality_status])->all()]);
    echo json_encode($manager->summary($run), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
} else {
    throw new RuntimeException('Use listing, dispatch, worker or snapshot <run>.');
}
