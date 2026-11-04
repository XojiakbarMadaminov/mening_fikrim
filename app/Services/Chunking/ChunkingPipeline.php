<?php

namespace App\Services\Chunking;

use App\Services\Legal\ChunkDocumentService;
use Illuminate\Database\Query\Builder;

class ChunkingPipeline
{
    public function __construct(private ChunkDocumentService $sources, private PassageGenerationService $retrieval) {}

    public function version(): string
    {
        return (string) config(config('ai.retrieval_enabled') ? 'ai.retrieval_version' : 'ai.version');
    }

    public function profile(int $size): string
    {
        return config('ai.retrieval_enabled') ? $this->retrieval->profile($size) : $this->sources->profile($size);
    }

    public function sources(): Builder
    {
        return $this->sources->sources();
    }

    public function pendingSources(int $size): Builder
    {
        return config('ai.retrieval_enabled') ? $this->retrieval->pendingSources($size) : $this->sources->pendingSources($size);
    }

    public function isCurrent(object $source, int $size): bool
    {
        return config('ai.retrieval_enabled') ? $this->retrieval->isCurrent($source, $size) : $this->sources->isCurrent($source, $size);
    }

    public function status(int $size): array
    {
        return config('ai.retrieval_enabled') ? $this->retrieval->status($size) : $this->sources->status($size);
    }

    public function process(int $id, int $size, bool $force = false, bool $dryRun = false): array
    {
        if (! config('ai.retrieval_enabled')) {
            return $this->sources->process($id, $size, $force, $dryRun);
        }
        // Existing exact source generations are reused. P3 force never deletes source chunks.
        $source = $this->sources->process($id, $size, false, $dryRun);
        if ($source['status'] === 'retry_exhausted') {
            return $source;
        }

        return $this->retrieval->process($id, $size, $force, $dryRun);
    }
}
