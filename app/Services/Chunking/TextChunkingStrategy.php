<?php

namespace App\Services\Chunking;

class TextChunkingStrategy implements ChunkingStrategy
{
    public function segments(string $text, iterable $sourceChunks, array $source): iterable
    {
        if ($text !== '') {
            yield ['id' => null, 'text' => $text, 'start' => 0, 'end' => strlen($text), 'type' => 'paragraph', 'number' => null, 'title' => null,
                'metadata' => ['context' => [], 'fallback' => true, 'contains_table' => (bool) preg_match('/^\s*\|/m', $text)]];
        }
    }
}
