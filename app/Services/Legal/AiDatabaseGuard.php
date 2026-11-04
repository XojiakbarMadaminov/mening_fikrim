<?php

namespace App\Services\Legal;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;

class AiDatabaseGuard
{
    public function connection(): Connection
    {
        if (app()->environment('production') || config('ai.connection') !== 'pgsql_ai') {
            throw new LogicException('AI operations require the isolated pgsql_ai connection outside production.');
        }
        $expected = app()->runningUnitTests() ? 'mening_fikrim_active_test' : 'mening_fikrim_ai_dev';
        $config = config('database.connections.pgsql_ai');
        if (($config['driver'] ?? '') !== 'pgsql' || ($config['database'] ?? '') !== $expected
            || ! empty($config['url']) || isset($config['read']) || isset($config['write'])) {
            throw new LogicException('AI database safety refusal: expected '.$expected.' without URL or read/write overrides.');
        }
        $db = DB::connection('pgsql_ai');
        $actual = $db->selectOne('SELECT current_database() AS name, current_schema() AS schema');
        if ($actual->name !== $expected || $actual->schema !== 'public') {
            throw new LogicException('AI database safety refusal: actual database/schema does not match the isolated target.');
        }

        return $db;
    }
}
