<?php

use App\Services\Scraping\DurableCampaign;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = $app['db']->connection();
if ($db->selectOne('select current_database() as n')->n !== 'mening_fikrim_active_test') {
    throw new RuntimeException('Only isolated test DB');
}
$app->make(Factory::class)->preventStrayRequests();
$c = $app->make(DurableCampaign::class);
$c->claim($argv[1]);
$c->reserve('crash-before-send');
echo "RESERVED\n";
flush();
while (true) {
    usleep(100000);
}
