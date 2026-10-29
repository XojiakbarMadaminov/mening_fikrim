<?php

/** Explicitly invoked P2 evidence driver. Never loaded by PHPUnit, scheduler or application requests. */
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\ScrapeRun;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzScraper;
use App\Services\Documents\DocumentImporter;
use App\Services\Documents\DocumentValidator;
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
$action = $argv[1] ?? '';
$expected = $action === 'inactive-update' ? 'mening_fikrim_active_test' : 'mening_fikrim_active';
if (DB::connection()->getDriverName() !== 'pgsql' || DB::selectOne('select current_database() as name')->name !== $expected) {
    throw new RuntimeException('Unexpected database; refusing the live driver.');
}
if (! config('scraping.live_enabled') && $action !== 'inactive-update') {
    throw new RuntimeException('This driver requires explicit SCRAPING_LIVE_ENABLED=true.');
}
$temporary = getenv('P2_LIVE_TMP');
if (! $temporary || ! is_dir($temporary) || ! str_starts_with($temporary, sys_get_temp_dir().'/lex-p2-')) {
    throw new RuntimeException('Provide a private temporary P2_LIVE_TMP directory.');
}
$save = function (string $name, array $value): void {
    file_put_contents(__DIR__.'/'.$name.'.json', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
};
Event::listen(ResponseReceived::class, function (ResponseReceived $event) use ($temporary): void {
    $url = $event->request->url();
    if (in_array($url, ['https://lex.uz/pdffile/8557098', 'https://lex.uz/pdffile/-8553692', 'https://lex.uz/uz/docs/-6257288', 'https://lex.uz/uz/actinfo/card1/8554353', 'https://lex.uz/uz/actinfo/card1/8554326'], true)) {
        file_put_contents($temporary.'/'.hash('sha256', $url).'.body', $event->response->body());
        file_put_contents($temporary.'/capture_index.jsonl', json_encode(['url' => $url, 'file' => hash('sha256', $url).'.body', 'sha256' => hash('sha256', $event->response->body())])."\n", FILE_APPEND);
    }
});
$http = app(LexHttpClient::class);
$http->context('p2-'.$action);
$scraper = app(LexUzScraper::class);
$manager = app(ScrapeRunManager::class);
$start = microtime(true);

if ($action === 'discovery') {
    $results = [];
    foreach (['nat', 'loc', 'int', 'tech', 'court', 'oliy'] as $section) {
        $pages = [];
        $result = $scraper->discover(['section' => $section, 'status' => 'Y'], function ($page) use (&$pages) {
            $pages[] = $page;

            return false;
        }, 1);
        $results[$section] = ['result' => $result, 'pages' => $pages];
        echo $section.' source total='.$result['total'].'; first page='.count($pages[0]['rows'])."\n";
        flush();
    }
    $segment = ['section' => 'nat', 'status' => 'Y', 'from' => '01.01.2010', 'to' => '31.01.2010'];
    $committed = [];
    $first = $scraper->discover($segment, function ($page) use (&$committed) {
        $committed = array_column($page['rows'], 'id');

        return false;
    }, 1);
    $seen = array_fill_keys($committed, true);
    $duplicates = 0;
    $pages = [];
    $resumed = $scraper->discover($segment, function ($page) use (&$seen, &$duplicates, &$pages) {
        $pages[] = ['page' => $page['page'], 'fingerprint' => $page['fingerprint'], 'prefix_fingerprint' => $page['prefix_fingerprint'], 'last_page' => $page['last_page']];
        foreach ($page['rows'] as $row) {
            if (isset($seen[$row['id']])) {
                $duplicates++;
            }
            $seen[$row['id']] = true;
        }

        return true;
    }, 8);
    $results['pagination_resume'] = ['segment' => $segment, 'first' => $first, 'resumed' => $resumed, 'committed_first_page_ids' => $committed, 'duplicates_skipped' => $duplicates, 'union_unique_ids' => count($seen), 'pages' => $pages];
    $results['seconds'] = microtime(true) - $start;
    $save('discovery_live', $results);
    echo 'Pagination/resume: '.json_encode($results['pagination_resume'])."\n";
} elseif ($action === 'dispatch') {
    $manifest = json_decode(file_get_contents(dirname(__DIR__).'/p0/sample_manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $ids = array_values(array_map(fn ($row) => $row['id'], array_filter($manifest, fn ($row) => ! in_array($row['id'], ['8181977', '-8102878', '-2509996'], true))));
    if (count($ids) !== 27) {
        throw new RuntimeException('Expected the bounded 27 active/pending candidates, including the metadata-first scan case.');
    }
    file_put_contents(__DIR__.'/sample_ids.json', json_encode($ids, JSON_PRETTY_PRINT)."\n");
    Artisan::call('documents:sync', ['source' => 'lex', '--mode' => 'full', '--limit' => 27, '--sample' => __DIR__.'/sample_ids.json']);
    echo Artisan::output();
    $run = ScrapeRun::latest('id')->firstOrFail();
    // Supply listing evidence only for IDs actually seen in this campaign's real root listing responses.
    $live = json_decode(file_get_contents(__DIR__.'/discovery_live.json'), true, flags: JSON_THROW_ON_ERROR);
    $statuses = [];
    foreach (['nat', 'loc', 'int', 'tech', 'court', 'oliy'] as $section) {
        foreach ($live[$section]['pages'][0]['rows'] as $row) {
            if (in_array($row['id'], $ids, true)) {
                $statuses[$row['id']] = $row['listing_status'];
            }
        }
    }
    $cp = $run->checkpoint;
    $cp['sample_listing_statuses'] = $statuses;
    $run->update(['checkpoint' => $cp]);
    echo 'Run ID='.$run->id.'; real listing corroborations='.count($statuses)."\n";
} elseif ($action === 'worker') {
    Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--tries' => 3, '--timeout' => 900, '--memory' => 512, '--sleep' => 1, '--stop-when-empty' => true, '--max-time' => 1800, '--max-jobs' => (int) ($argv[2] ?? 100)], new ConsoleOutput);
} elseif ($action === 'snapshot') {
    $run = ScrapeRun::findOrFail((int) ($argv[2] ?? 0));
    $manager->reconcile($run->id);
    $run->refresh();
    $data = ['run' => $run->toArray(), 'summary' => $manager->summary($run), 'rows' => []];
    foreach (['documents', 'document_identifiers', 'document_contents', 'jobs', 'failed_jobs'] as $table) {
        $data['rows'][$table] = DB::table($table)->count();
    }
    $data['contents'] = DB::table('document_contents')->orderBy('document_id')->orderBy('language')->get(['id', 'document_id', 'language', 'content_hash'])->toArray();
    $data['stored_markdown_examples'] = DB::table('document_contents')->orderBy('id')->limit(3)->get(['document_id', 'language', DB::raw('substring(content,1,220) as markdown_prefix')])->toArray();
    $save('run_'.$run->id, $data);
    echo json_encode($data['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
} elseif (in_array($action, ['inactive-fetch', 'inactive-quality'], true)) {
    $results = [];
    foreach (['8181977', '-8102878', '-2509996'] as $id) {
        $candidate = $scraper->fetch($id);
        $status = app(DocumentValidator::class)->status($candidate['status_evidence'], new DateTimeImmutable);
        if ($status['status']->value !== 'inactive') {
            throw new RuntimeException('Real inactive source status did not validate.');
        }
        $languages = [];
        foreach ($candidate['contents'] as $language => $text) {
            $validation = app(DocumentValidator::class)->content($text, null);
            $languages[$language] = ['errors' => $validation['errors'], 'quality' => $text['quality'] ?? [], 'chars' => mb_strlen($text['content'] ?? '')];
        }
        $results[$id] = ['url' => 'https://lex.uz/uz/docs/'.$id, 'status' => $status['status']->value, 'effective_at' => $status['effective_at'], 'languages' => $languages,
            'quality_valid' => array_all($languages, fn ($language) => $language['errors'] === [])];
        if ($id === '8181977') {
            file_put_contents($temporary.'/inactive_candidate.json', json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        echo $id." real source inactive verified\n";
    }
    $save('inactive_live', ['documents' => $results, 'seconds' => microtime(true) - $start]);
    if ($action === 'inactive-fetch') {
        $scan = $scraper->fetch('-8553692');
        file_put_contents($temporary.'/scan_candidate.json', json_encode($scan, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        echo "Actual scan candidate acquired for the isolated safe-update test; no development document admitted.\n";
    }
} elseif ($action === 'inactive-update') {
    // Isolated test DB: a controlled prior-active baseline, not an observed past legal state.
    Artisan::call('migrate', ['--seed' => true, '--force' => true]);
    $candidate = json_decode(file_get_contents($temporary.'/inactive_candidate.json'), true, flags: JSON_THROW_ON_ERROR);
    $source = DocumentSource::where('slug', 'lex')->firstOrFail();
    $document = Document::create(['source_id' => $source->id, 'external_id' => '8181977', 'title' => $candidate['title'], 'status' => 'active']);
    foreach ($candidate['contents'] as $language => $text) {
        $valid = app(DocumentValidator::class)->content($text, null);
        if ($valid['errors'] !== []) {
            throw new RuntimeException('Inactive baseline language is not valid.');
        }
        $document->contents()->create(['language' => $language, 'title' => $text['title'], 'content' => $valid['content'], 'content_hash' => $valid['hash']]);
    }
    $before = $document->contents()->pluck('content_hash', 'language')->all();
    $result = app(DocumentImporter::class)->import($source->id, $candidate, new DateTimeImmutable);
    $after = $document->fresh()->contents()->pluck('content_hash', 'language')->all();
    if ($before !== $after || $document->fresh()->status->value !== 'inactive') {
        throw new RuntimeException('Inactive update did not preserve the prior valid text.');
    }
    $save('inactive_update', ['database' => $expected, 'controlled_prior_state' => 'active', 'real_source_status' => 'inactive', 'result' => $result, 'hashes_before' => $before, 'hashes_after' => $after, 'content_rows' => count($after)]);
    echo 'Real-source inactive update preserved '.count($after)." language hashes in the isolated test DB.\n";
    $scan = json_decode(file_get_contents($temporary.'/scan_candidate.json'), true, flags: JSON_THROW_ON_ERROR);
    $previousChecked = '2026-10-20T12:00:00+05:00';
    $scanDocument = Document::create(['source_id' => $source->id, 'external_id' => '-8553692', 'title' => $scan['title'], 'status' => 'pending', 'last_checked_at' => $previousChecked]);
    $priorText = 'Синов учун аввал тасдиқланган матн. Бу ҳақиқий OCR сертификати эмас, controlled test baseline.';
    $oldContent = $scanDocument->contents()->create(['language' => 'uz-Cyrl', 'title' => 'Controlled prior validated test state', 'content' => $priorText, 'content_hash' => hash('sha256', $priorText)]);
    $scanResult = app(DocumentImporter::class)->import($source->id, $scan, new DateTimeImmutable);
    $fresh = $scanDocument->fresh();
    if ($fresh->status->value !== 'active' || $fresh->effective_at->format('Y-m-d') !== '2026-10-23' || $oldContent->fresh()->content_hash !== hash('sha256', $priorText) || $fresh->last_checked_at->format('Y-m-d') !== '2026-10-20' || $scanResult['complete']) {
        throw new RuntimeException('Actual scan metadata/quality safe-update guard failed.');
    }
    $save('scan_safe_update', ['database' => $expected, 'controlled_prior_content' => true, 'real_source_status' => $fresh->status->value,
        'effective_at' => $fresh->effective_at->format('Y-m-d'), 'last_checked_at' => $fresh->last_checked_at->format(DATE_ATOM),
        'old_hash_preserved' => true, 'result' => $scanResult, 'stored_warnings' => $fresh->metadata['validation']['warnings']]);
    echo "Actual source metadata date and warning persisted; uncertified OCR preserved the controlled old text.\n";
} else {
    throw new RuntimeException('Unknown P2 evidence action.');
}
