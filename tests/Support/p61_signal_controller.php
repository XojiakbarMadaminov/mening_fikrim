<?php

use Illuminate\Console\Signals;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT current_database() AS name')->name !== 'mening_fikrim_active_test') {
    throw new RuntimeException('Isolated test database only.');
}
Http::preventStrayRequests();
config(['scraping.live_enabled' => true]);
Signals::resolveAvailabilityUsing(fn () => true); // Tests normally disable command signal traps.
$status = Artisan::call('documents:campaign', ['action' => 'run', '--id' => $argv[1]]);
echo Artisan::output();
exit($status);
