<?php

namespace App\Services\Legal;

class LegalChunkVerifier
{
    public function __construct(private AiDatabaseGuard $guard, private ChunkDocumentService $service) {}

    public function verify(int $contentId, ?int $size = null): array
    {
        $size ??= (int) config('ai.chunk_size');
        $db = $this->guard->connection();
        $source = $this->service->sources()->where('c.id', $contentId)->first();
        if (! $source) {
            return ['content_id' => $contentId, 'valid' => false, 'errors' => ['missing_source']];
        }
        $set = $db->table('document_chunk_sets')->where('document_content_id', $contentId)->where('profile_hash', $this->service->profile($size))->where('is_current', true)->first();
        if (! $set) {
            return ['content_id' => $contentId, 'valid' => false, 'errors' => ['missing_current_generation']];
        }
        $errors = [];
        $count = 0;
        $offset = 0;
        $hash = hash_init('sha256');
        $characters = 0;
        $max = 0;
        $review = 0;
        $oversize = 0;
        $extraction = json_decode($source->extraction_metadata, true);
        $url = $extraction['source_url'] ?? $extraction['url'] ?? $source->source_url;
        foreach ($db->table('document_chunks')->where('chunk_set_id', $set->id)->orderBy('chunk_index')->cursor() as $chunk) {
            $m = json_decode($chunk->metadata, true, flags: JSON_THROW_ON_ERROR);
            if ($chunk->chunk_index !== $count || $m['start_byte'] !== $offset || $m['end_byte'] !== $offset + strlen($chunk->content)
                || substr($source->content, $offset, strlen($chunk->content)) !== $chunk->content || hash('sha256', $chunk->content) !== $chunk->content_hash
                || $chunk->source_checksum !== $source->content_hash || $chunk->document_id !== $source->document_id || $chunk->document_content_id !== $source->id
                || $chunk->language !== $source->language || $chunk->source_url !== $url || $chunk->chunking_version !== $set->chunking_version || $chunk->source_checksum !== $set->source_checksum) {
                $errors[] = ['chunk_id' => $chunk->id, 'reason' => 'offset_identity_or_text_hash'];
            }
            if ($source->quality_status === 'needs_review' && $chunk->quality_status !== 'needs_review') {
                $errors[] = ['chunk_id' => $chunk->id, 'reason' => 'source_quality_promoted'];
            }
            hash_update($hash, $chunk->content);
            $offset += strlen($chunk->content);
            $count++;
            $length = mb_strlen($chunk->content);
            $characters += $length;
            $max = max($max, $length);
            $review += (int) ($chunk->quality_status === 'needs_review');
            $oversize += (int) $m['oversize'];
        }
        if ($count !== $set->chunk_count || $offset !== strlen($source->content) || hash_final($hash) !== hash('sha256', $source->content)
            || $set->input_fingerprint !== $this->service->fingerprint($source)) {
            $errors[] = 'generation_count_or_exact_coverage';
        }

        return ['content_id' => $contentId, 'document_id' => $source->document_id, 'generation_id' => $set->id, 'language' => $source->language, 'chunks' => $count,
            'source_bytes' => $offset, 'characters' => $characters, 'maximum_chunk_chars' => $max, 'needs_review_chunks' => $review, 'oversize_chunks' => $oversize, 'valid' => $errors === [], 'errors' => $errors];
    }
}
