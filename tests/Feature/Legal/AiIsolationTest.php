<?php

namespace Tests\Feature\Legal;

use App\Providers\AiDevelopmentServiceProvider;
use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Connection;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Mockery;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AiIsolationTest extends TestCase
{
    public function test_actual_main_database_is_rejected_using_only_a_mock_select(): void
    {
        config(['ai.connection' => 'pgsql_ai', 'database.connections.pgsql_ai' => config('database.connections.pgsql')]);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('selectOne')->once()->with('SELECT current_database() AS name, current_schema() AS schema')
            ->andReturn((object) ['name' => 'mening_fikrim_active', 'schema' => 'public']);
        // No real MAIN connection is opened; any extra SQL/mutation fails this strict mock.
        DB::shouldReceive('connection')->once()->with('pgsql_ai')->andReturn($connection);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('actual database/schema');
        app(AiDatabaseGuard::class)->connection();
    }

    public function test_ai_migration_refuses_main_before_schema_writes(): void
    {
        config(['ai.connection' => 'pgsql']);
        DB::shouldReceive('connection')->never();
        $migration = require database_path('ai-migrations/2026_11_02_000001_create_legal_chunks.php');
        $this->expectException(\LogicException::class);
        $migration->up();
    }

    public function test_parser_and_scheduler_commands_are_disabled_in_ai_development(): void
    {
        config(['ai.enabled' => true]);
        (new AiDevelopmentServiceProvider($this->app))->boot();
        foreach (['documents:campaign', 'documents:sync', 'documents:quality-recheck', 'schedule:run', 'queue:retry'] as $command) {
            try {
                Event::dispatch(new CommandStarting($command, new ArrayInput([]), new BufferedOutput));
                self::fail('Parser/scheduler command was permitted: '.$command);
            } catch (\LogicException $e) {
                self::assertStringContainsString('disabled in AI DEV', $e->getMessage());
            }
        }
    }

    public function test_ordinary_migration_is_blocked_in_ai_mode(): void
    {
        config(['ai.enabled' => true, 'ai.connection' => 'pgsql_ai', 'database.connections.pgsql_ai' => config('database.connections.pgsql')]);
        DB::extend('pgsql_ai', fn () => DB::connection('pgsql'));
        (new AiDevelopmentServiceProvider($this->app))->boot();
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Use legal:migrate');
        $kernel = app(Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
        Artisan::call('migrate');
    }

    public function test_ai_mode_blocks_parser_jobs_before_execution(): void
    {
        config(['ai.enabled' => true, 'ai.connection' => 'pgsql_ai', 'database.connections.pgsql_ai' => config('database.connections.pgsql')]);
        DB::extend('pgsql_ai', fn () => DB::connection('pgsql'));
        (new AiDevelopmentServiceProvider($this->app))->boot();
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('payload')->andReturn(['displayName' => 'App\\Jobs\\ImportDocumentJob']);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Only legal chunking jobs');
        Event::dispatch(new JobProcessing('database', $job));
    }
}
