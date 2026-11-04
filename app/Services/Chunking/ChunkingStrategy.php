<?php

namespace App\Services\Chunking;

interface ChunkingStrategy
{
    /** Exact source slices with byte offsets and observed context; never synthesized legal content. */
    public function segments(string $text, iterable $sourceChunks, array $source): iterable;
}
