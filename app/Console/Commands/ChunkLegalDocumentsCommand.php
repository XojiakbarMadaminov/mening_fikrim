<?php

namespace App\Console\Commands;

use App\Jobs\ChunkLegalDocumentJob;
use App\Services\Chunking\ChunkingPipeline;
use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChunkLegalDocumentsCommand extends Command
{
    protected $signature = 'legal:chunk-documents
        {--limit=100 : Maximum distinct documents, 1..500}
        {--document-id=* : Only these document IDs; all stored languages are included}
        {--content-limit= : Maximum source representations per call, 1..500}
        {--content-id=* : Only these source content IDs}
        {--dry-run : Compute without writes or dispatch}
        {--force : Retry exhausted inputs; preserve successful P3 generations}
        {--only-pending : Skip completed unchanged source representations}
        {--chunk-size= : Target Unicode characters, 200..32000; atomic blocks can exceed it}
        {--queue : Dispatch to isolated ai_jobs / legal-chunking}
        {--status : Report counts without processing}';

    protected $description = 'Deterministic, resumable legal chunking exclusively in the AI DEV database';

    public function handle(AiDatabaseGuard $guard, ChunkingPipeline $service): int
    {
        // Must be the very first database operation, before status, queue or validation queries.
        $db = $guard->connection();
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $size = $this->option('chunk-size') === null ? (int) config('ai.chunk_size') : filter_var($this->option('chunk-size'), FILTER_VALIDATE_INT);
        $ids = $this->option('document-id');
        $contentIds = $this->option('content-id');
        $contentLimit = $this->option('content-limit') === null ? PHP_INT_MAX : filter_var($this->option('content-limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 500 || $size === false || $size < 200 || $size > 32000
            || $contentLimit === false || $contentLimit < 1 || ($this->option('content-limit') !== null && $contentLimit > 500)
            || count($contentIds) > 500 || array_any($contentIds, fn ($id) => ! ctype_digit((string) $id) || (int) $id < 1)
            || count($ids) > 500 || array_any($ids, fn ($id) => ! ctype_digit((string) $id) || (int) $id < 1)
            || ($this->option('force') && $this->option('only-pending')) || ($this->option('dry-run') && $this->option('queue'))) {
            $this->error('Invalid bounds/options: limit 1..500; chunk-size 200..32000; positive IDs; force/pending and dry-run/queue are mutually exclusive.');

            return self::FAILURE;
        }
        $profile = $service->profile($size);
        if ($this->option('status')) {
            $this->line(json_encode($service->status($size), JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        $documents = $db->table('documents')->orderBy('id');
        if ($ids !== []) {
            $documents->whereIn('id', $ids);
        }
        if ($contentIds !== []) {
            $documents->whereExists(fn ($q) => $q->selectRaw('1')->from('document_contents as c')->whereColumn('c.document_id', 'documents.id')->whereIn('c.id', $contentIds));
        }
        if ($this->option('only-pending') || ($this->option('queue') && ! $this->option('force'))) {
            $documents->whereExists($service->pendingSources($size)->whereColumn('c.document_id', 'documents.id'));
        }
        $selectedContents = 0;
        $selected = 0;
        $errors = 0;
        foreach ($documents->lazyById((int) config('ai.batch_size')) as $document) {
            if ($selected >= $limit) {
                break;
            }
            $counted = false;
            $sources = ($this->option('only-pending') || ($this->option('queue') && ! $this->option('force'))) ? $service->pendingSources($size) : $service->sources();
            if ($contentIds !== []) {
                $sources->whereIn('c.id', $contentIds);
            }
            foreach ($sources->where('c.document_id', $document->id)->orderBy('c.id')->cursor() as $source) {
                if ($selectedContents >= $contentLimit) {
                    break 2;
                }
                if (($this->option('only-pending') || $this->option('queue')) && ! $this->option('force') && $service->isCurrent($source, $size)) {
                    continue;
                }
                if (! $counted) {
                    $selected++;
                    $counted = true;
                }
                $selectedContents++;
                try {
                    if ($this->option('queue')) {
                        // Pin the queue's actual database; it must never fall back to the parser queue.
                        if (config('queue.connections.ai_database.connection') !== 'pgsql_ai' || config('queue.connections.ai_database.table') !== 'ai_jobs'
                            || config('queue.connections.ai_database.driver') !== 'database') {
                            throw new \LogicException('AI queue configuration is not isolated.');
                        }
                        ChunkLegalDocumentJob::dispatch((int) $source->id, $size, (bool) $this->option('force'));
                        $result = ['content_id' => $source->id, 'document_id' => $document->id, 'status' => 'queued'];
                    } else {
                        $result = $service->process((int) $source->id, $size, (bool) $this->option('force'), (bool) $this->option('dry-run'));
                    }
                    if ($result['status'] === 'retry_exhausted') {
                        $errors++;
                    }
                    $this->line(json_encode($result, JSON_THROW_ON_ERROR));
                } catch (Throwable $e) {
                    $errors++;
                    Log::error('Legal chunking failed', ['document_id' => $document->id, 'document_content_id' => $source->id, 'exception_class' => $e::class]);
                    $this->error('Chunking failed: document '.$document->id.', content '.$source->id.' ('.$e::class.'). See isolated log; retry is safe.');
                }
            }
        }
        $this->line(json_encode(['selected_documents' => $selected, 'selected_contents' => $selectedContents, 'errors' => $errors], JSON_THROW_ON_ERROR));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
