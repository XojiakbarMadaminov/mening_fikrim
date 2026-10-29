<?php

use App\Services\Documents\DocumentImporter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;

// Test-only child process. It cannot choose a non-test database or contact Lex.uz.
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__).'/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$connection = $app['db']->connection();
if ($connection->getDriverName() !== 'pgsql' || $connection->getDatabaseName() !== 'mening_fikrim_active_test') {
    throw new RuntimeException('Child import requires a PostgreSQL _test database.');
}
$app->make(Factory::class)->preventStrayRequests();
$connection->statement("SET application_name = 'p1_race_".getmypid()."'");
$input = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
echo "READY\n";
flush();
$deadline = microtime(true) + 15;
while (! is_file($argv[3])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Test barrier timed out.');
    }
    usleep(10000);
}
$result = $app->make(DocumentImporter::class)->import((int) $argv[1], $input, new DateTimeImmutable('2026-10-28T12:00:00+05:00'));
echo json_encode($result, JSON_THROW_ON_ERROR)."\n";
