<?php

namespace App\Console\Commands;

use App\Services\Scraping\PilotCampaign;
use Illuminate\Console\Command;

class ManagePilotCampaign extends Command
{
    protected $signature = 'documents:pilot {action=status : status, start, followup, advance or stop} {--id=} {--requests=} {--target=} {--stage=} {--after=} {--sources=} {--reason=operator_stop}';

    protected $description = 'Manage the finite, shared Lex.uz pilot budget; a stopped campaign cannot automatically restart';

    public function handle(PilotCampaign $pilot): int
    {
        $action = $this->argument('action');
        if ($action === 'start') {
            $pilot->create((string) $this->option('id'), (int) $this->option('requests'), (int) $this->option('target'));
        } elseif ($action === 'followup') {
            $pilot->followup((string) $this->option('after'), (string) $this->option('id'), (int) $this->option('requests'), (int) $this->option('sources'));
        } elseif ($action === 'advance') {
            $pilot->advance((int) $this->option('stage'));
        } elseif ($action === 'stop') {
            $pilot->stop((string) $this->option('reason'));
        } elseif ($action !== 'status') {
            $this->error('Use status, start, followup, advance or stop.');

            return self::FAILURE;
        }
        $this->line(json_encode(['campaign' => $pilot->state(), 'new_canonical_documents' => $pilot->newCount()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
