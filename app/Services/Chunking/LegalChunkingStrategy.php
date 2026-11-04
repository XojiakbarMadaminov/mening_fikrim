<?php

namespace App\Services\Chunking;

use App\Services\Legal\LegalDocumentChunker;

class LegalChunkingStrategy implements ChunkingStrategy
{
    public function __construct(private LegalDocumentChunker $legal) {}

    public function segments(string $text, iterable $sourceChunks, array $source): iterable
    {
        $offset = 0;
        foreach ($sourceChunks as $chunk) {
            $chunk = (array) $chunk;
            $metadata = is_string($chunk['metadata']) ? json_decode($chunk['metadata'], true, flags: JSON_THROW_ON_ERROR) : $chunk['metadata'];
            yield ['id' => $chunk['id'] ?? null, 'text' => $chunk['content'], 'start' => $metadata['start_byte'], 'end' => $metadata['end_byte'],
                'type' => $chunk['section_type'], 'number' => $chunk['section_number'], 'title' => $chunk['section_title'], 'metadata' => $metadata];
            $offset = $metadata['end_byte'];
        }
        if ($offset === 0 && $text !== '') {
            foreach ($this->legal->chunk($text, 4000, $source) as $chunk) {
                yield ['id' => null, 'text' => $chunk['content'], 'start' => $chunk['metadata']['start_byte'], 'end' => $chunk['metadata']['end_byte'],
                    'type' => $chunk['section_type'], 'number' => $chunk['section_number'], 'title' => $chunk['section_title'], 'metadata' => $chunk['metadata']];
            }
        }
    }
}
