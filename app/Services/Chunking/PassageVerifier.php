<?php

namespace App\Services\Chunking;

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\ChunkDocumentService;

class PassageVerifier
{
    public function __construct(private AiDatabaseGuard $guard, private ChunkDocumentService $sources, private PassageGenerationService $passages, private EmbeddingTextBuilder $builder) {}

    public function verify(int $contentId, ?int $size = null): array
    {
        $size ??= (int) config('ai.chunk_size');
        $db = $this->guard->connection();
        $source = $this->sources->sources()->where('c.id', $contentId)->first();
        $set = $db->table('document_chunk_sets')->where('document_content_id', $contentId)->where('layer', 'retrieval')->where('is_current', true)->where('profile_hash', $this->passages->profile($size))->first();
        if (! $source || ! $set) {
            return ['content_id' => $contentId, 'valid' => false, 'errors' => ['missing_source_or_current_passages']];
        }
        $parent = $db->table('document_chunk_sets')->where('id', $set->source_generation_id)->first();
        $chunks = [];
        foreach ($db->table('document_chunks')->where('chunk_set_id', $set->source_generation_id)->select('id', 'metadata')->cursor() as $chunk) {
            $m = json_decode($chunk->metadata, true);
            $chunks[$chunk->id] = [$m['start_byte'], $m['end_byte']];
        }
        $errors = [];
        $byte = 0;
        $char = 0;
        $count = 0;
        $hash = hash_init('sha256');
        $maximum = 0;
        $inputMaximum = 0;
        $shortBody = 0;
        $shortInput = 0;
        $ready = 0;
        $policy = $this->passages->policy($size);
        foreach ($db->table('retrieval_passages')->where('chunk_set_id', $set->id)->orderBy('passage_index')->cursor() as $p) {
            $meta = json_decode($p->metadata, true, flags: JSON_THROW_ON_ERROR);
            $lineage = json_decode($p->lineage, true, flags: JSON_THROW_ON_ERROR);
            $length = mb_strlen($p->body);
            if ($p->passage_index !== $count || $p->start_byte !== $byte || $p->start_char !== $char || $p->end_byte !== $byte + strlen($p->body) || $p->end_char !== $char + $length
                || substr($source->content, $byte, strlen($p->body)) !== $p->body || hash('sha256', $p->body) !== $p->body_hash || $length > $policy['passage_max_chars']
                || $p->document_id !== $source->document_id || $p->document_content_id !== $source->id || $p->language !== $source->language) {
                $errors[] = ['passage_id' => $p->id, 'reason' => 'body_offsets_hash_or_identity'];
            }
            $at = $p->start_byte;
            foreach ($lineage as $span) {
                $bounds = $chunks[$span['source_chunk_id']] ?? null;
                if (! $bounds || $span['start_byte'] !== $at || $span['end_byte'] <= $at || $span['start_byte'] < $bounds[0] || $span['end_byte'] > $bounds[1]
                    || $span['source_chunk_start_byte'] !== $bounds[0] || $span['source_chunk_end_byte'] !== $bounds[1]) {
                    $errors[] = ['passage_id' => $p->id, 'reason' => 'invalid_source_chunk_lineage'];
                }
                $at = $span['end_byte'];
            }
            if ($at !== $p->end_byte) {
                $errors[] = ['passage_id' => $p->id, 'reason' => 'incomplete_lineage'];
            }
            $input = $this->builder->build($p->body, [...$meta, 'lineage' => $lineage], $policy);
            if ($input['characters'] > $policy['embedding_max_chars'] || $input['text_hash'] !== $meta['embedding_input']['text_hash']
                || $meta['source_checksum'] !== $source->content_hash || $meta['quality']['source_extraction']['status'] !== $source->quality_status
                || $meta['quality']['human_review'] !== 'not_reviewed') {
                $errors[] = ['passage_id' => $p->id, 'reason' => 'input_context_or_quality'];
            }
            hash_update($hash, $p->body);
            $byte += strlen($p->body);
            $char += $length;
            $count++;
            $maximum = max($maximum, $length);
            $inputMaximum = max($inputMaximum, $input['characters']);
            $shortBody += (int) ($length < 80);
            $shortInput += (int) ($input['characters'] < 80);
            $ready += (int) ($p->suitability === 'eligible');
        }
        if ($byte !== strlen($source->content) || hash_final($hash) !== hash('sha256', $source->content) || $count !== $set->chunk_count
            || $set->input_fingerprint !== $this->sources->fingerprint($source) || ! $parent || ! $parent->is_current || $parent->layer !== 'source'
            || $parent->document_content_id !== $contentId || $parent->source_checksum !== $source->content_hash) {
            $errors[] = 'generation_coverage_or_source_parent';
        }

        return ['content_id' => $contentId, 'document_id' => $source->document_id, 'generation_id' => $set->id, 'passages' => $count, 'source_chunks' => count($chunks), 'source_bytes' => $byte,
            'maximum_body_chars' => $maximum, 'maximum_input_chars' => $inputMaximum, 'short_bodies' => $shortBody, 'short_inputs' => $shortInput, 'eligible' => $ready, 'valid' => $errors === [], 'errors' => $errors];
    }
}
