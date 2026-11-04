<?php

namespace App\Services\Chunking;

class EmbeddingTextBuilder
{
    public function build(string $body, array $metadata, array $policy): array
    {
        $maximum = (int) ($policy['embedding_max_chars'] ?? 4096);
        $budget = min((int) ($policy['context_max_chars'] ?? 512), $maximum - mb_strlen($body));
        if ($budget < 0) {
            throw new \LengthException('Passage body exceeds the embedding-input character bound.');
        }
        $lines = [];
        $seen = [];
        $candidates = ['Document' => $metadata['document_title'] ?? null];
        foreach (['section' => 'Section', 'chapter' => 'Chapter', 'article' => 'Article'] as $key => $label) {
            $candidates[$label] = $metadata['context'][$key]['title'] ?? null;
        }
        if (($metadata['single_unit']['type'] ?? null) === 'point') {
            $candidates['Point'] = $metadata['single_unit']['title'] ?? null;
        }
        foreach ($candidates as $label => $value) {
            if (! is_string($value) || trim($value) === '' || isset($seen[$value]) || str_contains($body, $value)) {
                continue;
            }
            $lines[] = $label.': '.$value;
            $seen[$value] = true;
        }
        $full = $lines === [] ? '' : implode("\n", $lines)."\nText:\n";
        $prefix = mb_substr($full, 0, $budget, 'UTF-8');
        $input = $prefix.$body;

        return ['text' => $input, 'text_hash' => hash('sha256', $input), 'characters' => mb_strlen($input),
            'estimated_tokens' => (int) ceil(mb_strlen($input) / 4), 'token_count_method' => 'approximate_chars_div_4_no_model_tokenizer',
            'body_start_char' => mb_strlen($prefix), 'context_truncated' => $prefix !== $full, 'input_builder_version' => 'context-v1',
            'source_document_id' => $metadata['document_id'] ?? null, 'source_content_id' => $metadata['document_content_id'] ?? null,
            'source_checksum' => $metadata['source_checksum'] ?? null, 'language' => $metadata['language'] ?? null,
            'profile_version' => $metadata['profile_version'] ?? null, 'source_spans' => $metadata['lineage'] ?? []];
    }
}
