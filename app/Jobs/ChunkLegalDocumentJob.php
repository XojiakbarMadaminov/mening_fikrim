<?php

namespace App\Jobs;

use App\Services\Chunking\ChunkingPipeline;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChunkLegalDocumentJob implements ShouldQueue
{
    use Queueable;

    public string $profileHash;

    public string $chunkingVersion;

    public int $tries = 3;

    public int $timeout = 900;

    public function __construct(public int $contentId, public int $chunkSize = 4000, public bool $force = false)
    {
        $this->chunkingVersion = app(ChunkingPipeline::class)->version();
        $this->profileHash = app(ChunkingPipeline::class)->profile($this->chunkSize);
        $this->onConnection('ai_database');
        $this->onQueue('legal-chunking');
    }

    public function handle(ChunkingPipeline $service): void
    {
        if ($this->chunkingVersion !== $service->version() || $this->profileHash !== $service->profile($this->chunkSize)) {
            throw new \LogicException('Queued legal profile differs from the loaded algorithm; refusing silent version drift.');
        }
        $service->process($this->contentId, $this->chunkSize, $this->force);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Legal chunking job failed', ['document_content_id' => $this->contentId, 'exception_class' => $exception ? $exception::class : null]);
    }
}
