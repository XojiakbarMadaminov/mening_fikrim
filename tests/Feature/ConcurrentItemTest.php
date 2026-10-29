<?php

namespace Tests\Feature;

use App\Models\DocumentSource;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentItemTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_postgresql_processes_commit_one_opaque_item_and_one_queue_receipt(): void
    {
        $source = DocumentSource::create(['name' => 'Lex', 'slug' => 'lex', 'type' => 'scraper', 'is_active' => true]);
        $run = app(ScrapeRunManager::class)->start($source, 'full', 1);
        DB::table('jobs')->delete();
        $directory = sys_get_temp_dir().'/p3-item-race-'.bin2hex(random_bytes(6));
        File::makeDirectory($directory, 0700);
        $processes = [];
        DB::beginTransaction();
        try {
            DB::table('scrape_runs')->where('id', $run->id)->lockForUpdate()->first();
            for ($i = 0; $i < 2; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/item_race.php'), (string) $run->id, $directory.'/go'], base_path(), timeout: 20);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 10;
            do {
                $ready = array_all($processes, fn ($p) => str_contains($p->getOutput(), 'READY'));
                usleep(10000);
            } while (! $ready && microtime(true) < $deadline);
            $this->assertTrue($ready);
            touch($directory.'/go');
            $waiters = 0;
            do {
                $waiters = (int) DB::selectOne("select count(*) AS n from pg_stat_activity where application_name like 'p3_item_race_%' and wait_event_type='Lock'")->n;
                usleep(10000);
            } while ($waiters < 2 && microtime(true) < $deadline);
            $this->assertSame(2, $waiters);
            DB::commit();
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }
            $this->assertSame(['opaque:-001'], $run->items()->pluck('source_external_id')->all());
            $this->assertDatabaseCount('jobs', 1);
            $item = $run->items()->first();
            $this->assertSame('queued', $item->status);
            $this->assertSame((int) DB::table('jobs')->value('id'), $item->queue_job_id);
            File::ensureDirectoryExists(storage_path('app/test-diagnostics'));
            File::put(storage_path('app/test-diagnostics/item_concurrency.json'), json_encode(['processes' => 2, 'lock_waiters' => $waiters, 'items' => 1, 'jobs' => 1]));
        } finally {
            if (DB::transactionLevel()) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            File::deleteDirectory($directory);
        }
    }
}
