<?php

namespace App\Services\Chunking;

use InvalidArgumentException;
use LogicException;

class ChunkingService
{
    public function __construct(private LegalChunkingStrategy $legal, private TextChunkingStrategy $text, private BoundedTextSplitter $splitter,
        private ChunkQualityAnalyzer $quality, private EmbeddingTextBuilder $input) {}

    public function build(string $type, string $text, iterable $sourceChunks, array $source, array $policy): array
    {
        $strategy = match ($type) {
            'legal' => $this->legal, 'text' => $this->text, default => throw new InvalidArgumentException('Unsupported strategy; implement the small ChunkingStrategy contract first.')
        };
        $target = (int) $policy['passage_target_chars'];
        $maximum = (int) $policy['passage_max_chars'];
        if ($target < 80 || $maximum < $target || $maximum > 32000 || $maximum + (int) $policy['context_max_chars'] > (int) $policy['embedding_max_chars']) {
            throw new InvalidArgumentException('Inconsistent bounded passage/context policy.');
        }
        $result = [];
        $buffer = null;
        $sourceByte = 0;
        $sourceChar = 0;
        $epoch = 0;
        foreach ($strategy->segments($text, $sourceChunks, $source) as $segment) {
            if ($segment['start'] !== $sourceByte || strlen($segment['text']) !== $segment['end'] - $segment['start']
                || substr($text, $segment['start'], strlen($segment['text'])) !== $segment['text']) {
                throw new LogicException('Source segment lineage/coverage mismatch.');
            }
            $context = $segment['metadata']['context'] ?? [];
            $heading = in_array($segment['type'], ['title', 'document_part', 'section', 'chapter', 'appendix', 'heading'], true)
                || ($segment['type'] === 'article' && preg_match('/^\s*(?:#{1,6}\s*)?(?:\d+[⁰¹²³⁴⁵⁶⁷⁸⁹]*\s*[-–—]\s*(?:modda|модда)|Статья\s+\d+)/ui', $segment['text']));
            if ($heading) {
                $epoch++;
            }
            $scope = hash('sha256', json_encode([$epoch, $context], JSON_THROW_ON_ERROR));
            $table = (bool) ($segment['metadata']['contains_table'] ?? false);
            foreach ($this->splitter->split($segment['text'], $target, $maximum, $table) as $part) {
                $characters = mb_strlen($part['text']);
                $start = $segment['start'] + $part['start'];
                $end = $segment['start'] + $part['end'];
                $unit = ['type' => $segment['type'], 'number' => $segment['number'], 'title' => is_string($segment['title']) ? mb_substr($segment['title'], 0, 300) : null];
                $span = ['source_chunk_id' => $segment['id'], 'source_chunk_start_byte' => $segment['start'], 'source_chunk_end_byte' => $segment['end'],
                    'start_byte' => $start, 'end_byte' => $end, 'section_type' => $segment['type'], 'section_number' => $segment['number']];
                $fragment = ['body' => $part['text'], 'start_byte' => $start, 'end_byte' => $end, 'start_char' => $sourceChar,
                    'end_char' => $sourceChar + $characters, 'characters' => $characters, 'scope' => $scope, 'lineage' => [$span],
                    'context' => $context, 'unit_key' => $segment['id'] ?? 'offset-'.$segment['start'], 'single_unit' => $unit,
                    'table' => $table, 'fallback' => (bool) ($segment['metadata']['fallback'] ?? false), 'semantic_fragment' => $part['semantic_fragment'],
                    'boundaries' => [$part['boundary']], 'header_only' => in_array($segment['type'], ['title', 'document_part', 'section', 'chapter', 'appendix', 'heading'], true)
                        && preg_replace('/^\s*#{1,6}\s*/u', '', trim($segment['text'])) === $segment['title']];
                $sourceChar += $characters;
                if ($buffer !== null && ($buffer['scope'] === $scope || ($buffer['header_only'] && $this->compatibleParent($buffer['context'], $context)))
                    && ($buffer['characters'] < (int) $policy['short_merge_chars'] || $characters < (int) $policy['short_merge_chars'])
                    && $maximum >= $buffer['characters'] + $characters) {
                    if ($buffer['header_only']) {
                        $buffer['context'] = $context;
                        $buffer['scope'] = $scope;
                    }
                    $buffer['header_only'] = $buffer['header_only'] && $fragment['header_only'];
                    $buffer['body'] .= $fragment['body'];
                    $buffer['end_byte'] = $end;
                    $buffer['end_char'] = $fragment['end_char'];
                    $buffer['characters'] += $characters;
                    $buffer['lineage'] = array_merge($buffer['lineage'], $fragment['lineage']);
                    $buffer['boundaries'] = array_merge($buffer['boundaries'], $fragment['boundaries']);
                    if ($buffer['unit_key'] !== $fragment['unit_key']) {
                        $buffer['single_unit'] = null;
                    }
                    $buffer['table'] = $buffer['table'] || $table;
                    $buffer['fallback'] = $buffer['fallback'] || $fragment['fallback'];
                    $buffer['semantic_fragment'] = $buffer['semantic_fragment'] || $fragment['semantic_fragment'];
                } else {
                    if ($buffer !== null) {
                        $result[] = $this->finish($buffer, $source, $policy);
                    }
                    $buffer = $fragment;
                }
            }
            $sourceByte = $segment['end'];
        }
        if ($buffer !== null) {
            $result[] = $this->finish($buffer, $source, $policy);
        }
        if ($sourceByte !== strlen($text) || implode('', array_column($result, 'body')) !== $text) {
            throw new LogicException('Passages do not reconstruct the exact source.');
        }

        return $result;
    }

    private function compatibleParent(array $parent, array $child): bool
    {
        foreach (['document_part', 'section', 'chapter', 'article'] as $key) {
            if (($parent[$key] ?? null) !== null && ($child[$key] ?? null) !== null && $parent[$key] !== $child[$key]) {
                return false;
            }
        }

        return true;
    }

    private function finish(array $buffer, array $source, array $policy): array
    {
        $quality = $this->quality->analyze($buffer['body'], $source, $buffer);
        $metadata = ['document_id' => $source['document_id'] ?? null, 'document_content_id' => $source['document_content_id'] ?? null,
            'document_title' => $source['title'] ?? null, 'source_url' => $source['source_url'] ?? null, 'source_checksum' => $source['source_checksum'] ?? null,
            'language' => $source['language'] ?? null, 'profile_version' => $policy['retrieval_version'], 'context' => $buffer['context'],
            'single_unit' => $buffer['single_unit'], 'quality' => $quality, 'characters' => $buffer['characters'],
            'boundaries' => array_values(array_unique($buffer['boundaries'])), 'merged_source_fragments' => count($buffer['lineage']), 'overlap_bytes' => 0,
            'contains_table' => $buffer['table'], 'semantic_fragment' => $buffer['semantic_fragment']];
        $prepared = $this->input->build($buffer['body'], [...$metadata, 'lineage' => $buffer['lineage']], $policy);
        $metadata['embedding_input'] = array_diff_key($prepared, ['text' => true, 'source_spans' => true]);

        return ['body' => $buffer['body'], 'body_hash' => hash('sha256', $buffer['body']), 'start_byte' => $buffer['start_byte'], 'end_byte' => $buffer['end_byte'],
            'start_char' => $buffer['start_char'], 'end_char' => $buffer['end_char'], 'suitability' => $quality['retrieval_suitability'],
            'lineage' => $buffer['lineage'], 'metadata' => $metadata];
    }
}
