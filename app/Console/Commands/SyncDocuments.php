<?php

namespace App\Console\Commands;

use App\Models\DocumentSource;
use App\Models\ScrapeRun;
use App\Services\Scraping\ScrapeRunManager;
use Illuminate\Console\Command;

class SyncDocuments extends Command
{
    protected $signature = 'documents:sync {source=lex} {--mode=full} {--limit=} {--resume=} {--section= : Restrict a bounded test to one verified discovery root} {--from= : Inclusive source discovery date dd.mm.yyyy} {--to= : Inclusive end date dd.mm.yyyy} {--items= : Read-only item list for a run ID} {--status= : Optional item status filter} {--sample= : Explicit JSON array of at most 30 verified sample source IDs} {--report= : Show a stored run without dispatching work}';

    protected $description = 'Queue a bounded Lex.uz test import; unlimited imports are disabled in P2';

    public function handle(ScrapeRunManager $manager): int
    {
        if ($this->option('items') !== null) {
            $query = ScrapeRun::findOrFail((int) $this->option('items'))->items()->orderBy('id');
            if ($this->option('status') !== null) {
                $query->where('status', $this->option('status'));
            }
            foreach ($query->cursor() as $item) {
                $this->line(json_encode($item->only(['id', 'source_external_id', 'document_id', 'status', 'attempts', 'review_reasons', 'last_error']), JSON_UNESCAPED_UNICODE));
            }

            return self::SUCCESS;
        }
        if ($this->option('report') !== null) {
            $run = ScrapeRun::findOrFail((int) $this->option('report'));
            $manager->reconcile($run->id);
            $this->line(json_encode($manager->summary($run->fresh()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        if ($this->argument('source') !== 'lex' || ! config('scraping.live_enabled')) {
            $this->error('Only lex is supported; SCRAPING_LIVE_ENABLED must be explicitly enabled for bounded live work.');

            return self::FAILURE;
        }
        if ($this->option('resume') !== null) {
            $run = $manager->resume((int) $this->option('resume'));
            $manager->reconcile($run->id);
            $this->info('Resumed bounded run '.$run->id.'. Checkpoints replay fresh ASP.NET sessions.');

            return self::SUCCESS;
        }
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > config('scraping.max_documents_per_run')) {
            $this->error('An explicit --limit=1..30 is required. Full and 1000-document imports have no execution path in P2.');

            return self::FAILURE;
        }
        $source = DocumentSource::where('slug', 'lex')->firstOrFail();
        $sample = [];
        if ($this->option('sample')) {
            $path = $this->option('sample');
            if (! is_file($path) || filesize($path) > 10000) {
                $this->error('Sample must be a small local JSON file.');

                return self::FAILURE;
            }
            $sample = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($sample) || ! array_is_list($sample)) {
                $this->error('Sample must be a JSON array of source ID strings.');

                return self::FAILURE;
            }
        }
        $run = $manager->start($source, $this->option('mode'), $limit, $sample, $this->option('section'), $this->option('from'), $this->option('to'));
        $this->info('Queued bounded '.$this->option('mode').' run '.$run->id.' (limit '.$limit.'). Use one database worker on queue scraping.');
        $this->line('php artisan documents:sync lex --report='.$run->id);

        return self::SUCCESS;
    }
}
