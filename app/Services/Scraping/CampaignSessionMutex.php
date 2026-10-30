<?php

namespace App\Services\Scraping;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Coordination only. HTTP/import authority always remains DurableCampaign's original lock. */
class CampaignSessionMutex
{
    private ?int $pid = null;

    public function __construct(private int $key) {}

    public function acquire(): void
    {
        $pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        if ($this->pid !== null) {
            $held = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid() AND classid=1369487366::oid AND objid=?::oid AND granted) AS held", [$this->key])->held;
            if ($this->pid !== $pid || ! $held) {
                throw new RuntimeException('Coordination PostgreSQL session changed.');
            }

            return;
        }
        $held = DB::selectOne("SELECT EXISTS(SELECT 1 FROM pg_locks WHERE locktype='advisory' AND pid=pg_backend_pid() AND classid=1369487366::oid AND objid=?::oid AND granted) AS held", [$this->key])->held;
        if ($held || ! DB::selectOne('SELECT pg_try_advisory_lock(1369487366, ?) AS held', [$this->key])->held) {
            throw new RuntimeException('Another campaign '.($this->key === 2 ? 'orchestrator' : 'worker').' is already active.');
        }
        $this->pid = $pid;
    }

    public function release(): void
    {
        if ($this->pid !== null && $this->pid === (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid) {
            DB::select('SELECT pg_advisory_unlock(1369487366, ?)', [$this->key]);
        }
        $this->pid = null;
    }
}
