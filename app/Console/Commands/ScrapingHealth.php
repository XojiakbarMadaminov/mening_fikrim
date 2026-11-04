<?php

namespace App\Console\Commands;

use App\Services\Scraping\ScrapingDependencyHealth;
use Illuminate\Console\Command;

class ScrapingHealth extends Command
{
    protected $signature = 'documents:health {--json}';

    protected $description = 'Offline native PDF/OCR dependency, version, language and persistent-path preflight';

    public function handle(ScrapingDependencyHealth $health): int
    {
        $report = $health->report();
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
