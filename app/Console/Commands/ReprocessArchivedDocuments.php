<?php

namespace App\Console\Commands;

use App\Models\DocumentSource;
use App\Services\Scraping\ArchivedSourceReprocessor;
use Illuminate\Console\Command;

class ReprocessArchivedDocuments extends Command
{
    protected $signature = 'documents:reprocess {source=lex} {--limit=20} {--document=} {--source-id=} {--dry-run}';

    protected $description = 'Bounded offline extraction of verified archived HTML/PDF; retains original source observation times';

    public function handle(ArchivedSourceReprocessor $reprocessor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $documentId = $this->option('document') === null ? null : filter_var($this->option('document'), FILTER_VALIDATE_INT);
        $sourceId = $this->option('source-id');
        if ($limit === false || $limit < 1 || $limit > 30 || $documentId === false || ($documentId !== null && $documentId < 1)
            || ($sourceId !== null && ! preg_match('/^-?\d+$/D', $sourceId))) {
            $this->error('Use --limit=1..30, a positive --document ID and an opaque signed numeric --source-id.');

            return self::FAILURE;
        }
        $source = DocumentSource::where('slug', $this->argument('source'))->firstOrFail();
        if ($source->slug !== 'lex') {
            $this->error('Archived-source extraction currently supports only the existing Lex.uz parser.');

            return self::FAILURE;
        }
        $selected = $reprocessor->select($source, $limit, $documentId, $sourceId);
        $summary = ['source' => $source->slug, 'limit' => $limit, 'dry_run' => (bool) $this->option('dry-run'),
            'selected' => count($selected), 'processed' => 0, 'failed' => 0, 'technical_failures' => 0, 'network_requests' => 0, 'outcomes' => []];
        foreach ($selected as $observation) {
            $outcome = $reprocessor->reprocess($observation, $summary['dry_run']);
            $summary[$outcome['state'] === 'processed' ? 'processed' : 'failed']++;
            $summary['technical_failures'] += ($outcome['reason'] ?? null) === 'reprocess_technical_failure' ? 1 : 0;
            $summary['outcomes'][] = $outcome;
        }
        $summary['note'] = 'Original archived observations only; no HTTP, freshness claim, automatic OCR certification or content deletion.';
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $summary['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
