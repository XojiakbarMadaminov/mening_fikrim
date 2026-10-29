<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\Factory;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $connection = $app['db']->connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->getDatabaseName() !== 'mening_fikrim_active_test') {
            throw new \RuntimeException('Refusing to run database tests outside a dedicated mening_fikrim_active_test PostgreSQL database.');
        }
        if ($connection->selectOne('select current_database() as name')->name !== 'mening_fikrim_active_test') {
            throw new \RuntimeException('Actual PostgreSQL database is not mening_fikrim_active_test.');
        }
        $app->make(Factory::class)->preventStrayRequests();

        return $app;
    }
}
