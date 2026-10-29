<?php

use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active_test') {
    throw new RuntimeException('Item race requires the fixed test DB.');
}
$app->make(Factory::class)->preventStrayRequests();
DB::statement("SET application_name = 'p3_item_race_".getmypid()."'");
echo "READY\n";
flush();
$deadline = microtime(true) + 15;
while (! is_file($argv[2])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Item race barrier timed out.');
    }
    usleep(10000);
}
app(ScrapeRunManager::class)->selectPage((int) $argv[1], ['page' => 1, 'total' => 1, 'fingerprint' => 'controlled-concurrent-page', 'last_page' => true,
    'rows' => [['id' => 'opaque:-001', 'listing_status' => 'Y']]]);
echo "DONE\n";
