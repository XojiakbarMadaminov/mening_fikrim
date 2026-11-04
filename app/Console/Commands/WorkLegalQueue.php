<?php

namespace App\Console\Commands;

use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Console\Command;

class WorkLegalQueue extends Command
{
    protected $signature = 'legal:work {--max-jobs=50 : Stop after 1..500 jobs; also stops when empty} {--rest-ms=50 : Pause 0..2000ms between jobs}';

    protected $description = 'Run a bounded isolated AI queue worker with no parser queue or failed-job storage';

    public function handle(AiDatabaseGuard $guard): int
    {
        $db = $guard->connection();
        $max = filter_var($this->option('max-jobs'), FILTER_VALIDATE_INT);
        $rest = filter_var($this->option('rest-ms'), FILTER_VALIDATE_INT);
        if ($rest === false || $rest < 0 || $rest > 2000 || $max === false || $max < 1 || $max > 500 || ! config('ai.enabled')
            || config('database.default') !== 'pgsql_ai' || config('queue.connections.ai_database.connection') !== 'pgsql_ai'
            || config('queue.connections.ai_database.table') !== 'ai_jobs' || config('queue.connections.ai_database.driver') !== 'database') {
            $this->error('legal:work requires the isolated AI DEV environment and max-jobs 1..500.');

            return self::FAILURE;
        }
        config(['queue.failed.driver' => null, 'cache.default' => 'array']);
        app()->forgetInstance('queue.failer');

        if (! $db->selectOne('SELECT pg_try_advisory_lock(1095320912, 2) AS acquired')->acquired) {
            $this->error('Another isolated AI worker is already active.');

            return self::FAILURE;
        }
        try {
            return $this->call('queue:work', ['connection' => 'ai_database', '--queue' => 'legal-chunking', '--stop-when-empty' => true, '--max-jobs' => $max, '--tries' => 3, '--timeout' => 900, '--sleep' => 1, '--memory' => 256, '--rest' => $rest / 1000]);
        } finally {
            $db->selectOne('SELECT pg_advisory_unlock(1095320912, 2)');
        }
    }
}
