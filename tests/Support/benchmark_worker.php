<?php

use App\Scraping\Contracts\DocumentScraper;
use App\Scraping\LexUz\LexHttpClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\BenchmarkScraper;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active_test' || config('scraping.live_enabled')) {
    throw new RuntimeException('Offline worker requires the fixed test DB and live scraping disabled.');
}
Http::preventStrayRequests();
Http::fake(['https://lex.uz/__offline__/*' => function ($request) {
    usleep(20000); // Explicit controlled fake-network latency, not Lex.uz latency.
    $kind = (int) substr(parse_url($request->url(), PHP_URL_PATH), -1);
    $body = '<div id="divCont"><div class="ACT_TITLE">Controlled offline benchmark</div><div class="TEXT_HEADER_DEFAULT">1-bob. Qoidalar</div><p>'.str_repeat('Normativ test matni saqlanadi. ', 50).'</p>'.($kind >= 3 ? '<img src="/controlled-diagram.png">' : '').'</div>';

    return Http::response($body, 200, ['Content-Type' => 'text/html']);
}]);
$client = new class extends LexHttpClient
{
    protected function minimumIntervalMs(): int
    {
        // Only this test actor, after preventStrayRequests + fake registration, can simulate 1000 ms.
        return (int) getenv('P3_BENCH_INTERVAL');
    }
};
$app->instance(LexHttpClient::class, $client);
$app->bind(DocumentScraper::class, BenchmarkScraper::class);
DB::statement("SET application_name = 'p3_bench_".getmypid()."'");
$lockQueries = [];
$transactions = [];
$began = null;
DB::listen(function ($query) use (&$lockQueries) {
    if (str_contains(strtolower($query->sql), 'for update')) {
        $lockQueries[] = ['source_lock' => str_contains($query->sql, 'document_sources'), 'seconds' => $query->time / 1000];
    }
});
Event::listen(TransactionBeginning::class, function ($event) use (&$began) {
    if ($event->connection->transactionLevel() === 1) {
        $began = hrtime(true);
    }
});
Event::listen(TransactionCommitted::class, function ($event) use (&$began, &$transactions) {
    if ($event->connection->transactionLevel() === 0 && $began !== null) {
        $transactions[] = (hrtime(true) - $began) / 1e9;
        $began = null;
    }
});
$start = hrtime(true);
Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'scraping', '--tries' => 3, '--timeout' => 900, '--stop-when-empty' => true, '--sleep' => 1, '--max-time' => 180, '--max-jobs' => 30, '--quiet' => true]);
echo json_encode(['pid' => getmypid(), 'wall_seconds' => (hrtime(true) - $start) / 1e9, 'php_peak_bytes' => memory_get_peak_usage(true),
    'self_usage' => getrusage(), 'child_usage' => getrusage(1), 'lock_queries' => $lockQueries, 'transactions_seconds' => $transactions], JSON_THROW_ON_ERROR)."\n";
