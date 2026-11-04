<?php

namespace App\Console\Commands;

use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Console\Command;

class MigrateLegalDatabase extends Command
{
    protected $signature = 'legal:migrate';

    protected $description = 'Apply only isolated AI migrations, after verifying the actual PostgreSQL target';

    public function handle(AiDatabaseGuard $guard): int
    {
        $guard->connection();

        return $this->call('migrate', ['--database' => 'pgsql_ai', '--path' => ['database/ai-migrations'], '--force' => true]);
    }
}
