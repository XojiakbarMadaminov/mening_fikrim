<?php

namespace Tests\Feature;

use App\Models\DocumentSource;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Fixtures\FixtureInputs;
use Tests\TestCase;

class ConcurrentImportTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_real_php_processes_wait_on_postgresql_lock_and_converge_to_one_document(): void
    {
        $source = DocumentSource::create(['name' => 'Lex.uz', 'slug' => 'lex', 'type' => 'scraper']);
        $payload = tempnam(sys_get_temp_dir(), 'p1-payload-');
        $barrier = $payload.'.go';
        file_put_contents($payload, json_encode(FixtureInputs::seed(), JSON_THROW_ON_ERROR));
        $processes = [];
        $blocked = 0;
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/import_process.php'), (string) $source->id, $payload, $barrier], base_path(), ['APP_ENV' => 'testing'], timeout: 20);
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $buffer) => str_contains($buffer, 'READY')), $process->getErrorOutput());
            }
            DB::beginTransaction();
            DB::table('document_sources')->where('id', $source->id)->lockForUpdate()->first();
            touch($barrier);
            $deadline = microtime(true) + 10;
            do {
                // Clear the statistics snapshot held by this explicit transaction.
                DB::select('select pg_stat_clear_snapshot()');
                $blocked = (int) DB::selectOne("select count(*) as n from pg_stat_activity where datname = current_database() and application_name like 'p1_race_%' and wait_event_type = 'Lock'")->n;
                if ($blocked === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both child writers must actually contend for the PostgreSQL lock.');
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            }
            $this->assertSame($results[0]['document_id'], $results[1]['document_id']);
            $actions = array_column($results, 'action');
            sort($actions);
            $this->assertSame(['created', 'unchanged'], $actions);
            $this->assertDatabaseCount('documents', 1);
            $this->assertDatabaseCount('document_identifiers', 4);
            $this->assertDatabaseCount('document_contents', 3);
            @mkdir(storage_path('app/test-diagnostics'), 0700, true);
            file_put_contents(storage_path('app/test-diagnostics/concurrency.json'), json_encode(['processes' => 2, 'observed_postgresql_lock_waiters' => $blocked, 'results' => $results, 'rows' => ['documents' => 1, 'identifiers' => 4, 'contents' => 3]], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            @unlink($payload);
            @unlink($barrier);
        }
    }
}
