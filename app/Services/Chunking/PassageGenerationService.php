<?php

namespace App\Services\Chunking;

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\ChunkDocumentService;
use App\Services\Legal\LegalDocumentChunker;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class PassageGenerationService
{
    public function __construct(private AiDatabaseGuard $guard, private ChunkDocumentService $sources, private ChunkingService $engine) {}

    public function policy(int $size): array
    {
        return [...config('ai'), 'passage_target_chars' => min($size, (int) config('ai.passage_target_chars'))];
    }

    public function profile(int $size): string
    {
        $this->sources->profile($size);

        return hash('sha256', json_encode(['layer' => 'retrieval', 'source_profile' => $this->sources->profile($size),
            'version' => config('ai.retrieval_version'), 'target' => $this->policy($size)['passage_target_chars'], 'maximum' => config('ai.passage_max_chars'),
            'context' => config('ai.context_max_chars'), 'embedding_maximum' => config('ai.embedding_max_chars'), 'short_merge' => config('ai.short_merge_chars'), 'overlap' => 0, 'input_builder' => 'context-v1'], JSON_THROW_ON_ERROR));
    }

    public function pendingSources(int $size): Builder
    {
        return $this->sources->sources()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('document_chunk_sets as r')->whereColumn('r.document_content_id', 'c.id')
            ->where('r.layer', 'retrieval')->where('r.profile_hash', $this->profile($size))->where('r.is_current', true)->where('r.status', 'completed')->whereColumn('r.source_checksum', 'c.content_hash'));
    }

    public function isCurrent(object $source, int $size): bool
    {
        return $this->guard->connection()->table('document_chunk_sets')->where('document_content_id', $source->id)->where('profile_hash', $this->profile($size))
            ->where('input_fingerprint', $this->sources->fingerprint($source))->where('layer', 'retrieval')->where('is_current', true)->where('status', 'completed')->exists();
    }

    public function currentPassages(?int $size = null): Builder
    {
        $size ??= (int) config('ai.chunk_size');

        return $this->guard->connection()->table('retrieval_passages as p')->join('document_chunk_sets as r', 'r.id', '=', 'p.chunk_set_id')
            ->join('document_chunk_sets as s', 's.id', '=', 'r.source_generation_id')->join('document_contents as c', 'c.id', '=', 'r.document_content_id')
            ->join('documents as d', 'd.id', '=', 'c.document_id')->where('r.layer', 'retrieval')->where('r.is_current', true)->where('r.status', 'completed')
            ->where('r.profile_hash', $this->profile($size))->where('s.layer', 'source')->where('s.is_current', true)->where('s.status', 'completed')
            ->whereColumn('r.source_checksum', 'c.content_hash')->whereColumn('s.source_checksum', 'r.source_checksum')
            ->whereColumn('p.document_content_id', 'c.id')->whereColumn('p.document_id', 'd.id')->whereColumn('p.language', 'c.language');
    }

    public function status(int $size): array
    {
        $db = $this->guard->connection();
        $base = $this->sources->status($size);
        $sets = $db->table('document_chunk_sets')->where('layer', 'retrieval')->where('profile_hash', $this->profile($size));
        $failed = 0;
        $exhausted = 0;
        foreach ((clone $sets)->where('status', 'failed')->cursor() as $failure) {
            $source = $this->sources->sources()->where('c.id', $failure->document_content_id)->first();
            if ($source && $failure->input_fingerprint === $this->sources->fingerprint($source)) {
                $failed++;
                $exhausted += (int) ($failure->attempts >= (int) config('ai.max_attempts'));
            }
        }

        return [...$base, 'source_chunking_version' => config('ai.version'), 'retrieval_version' => config('ai.retrieval_version'), 'retrieval_profile_hash' => $this->profile($size),
            'processed_retrieval_contents' => (clone $sets)->where('is_current', true)->where('status', 'completed')->count(),
            'pending_retrieval_contents' => $this->pendingSources($size)->count(), 'failed_retrieval_generations' => (clone $sets)->where('status', 'failed')->count(),
            'failed_retrieval_contents' => $failed, 'retry_exhausted_retrieval_contents' => $exhausted,
            'current_passages' => $this->currentPassages($size)->count(), 'all_passages' => $db->table('retrieval_passages')->count()];
    }

    public function process(int $contentId, int $size, bool $force = false, bool $dryRun = false): array
    {
        $db = $this->guard->connection();
        $profile = $this->profile($size);
        try {
            return $db->transaction(function () use ($db, $contentId, $size, $force, $dryRun, $profile) {
                $source = $this->sources->sources()->where('c.id', $contentId)->lock('FOR UPDATE OF c,d')->first();
                if (! $source) {
                    throw new \RuntimeException('Missing source content.');
                }
                $parent = $db->table('document_chunk_sets')->where('document_content_id', $contentId)->where('layer', 'source')->where('profile_hash', $this->sources->profile($size))
                    ->where('input_fingerprint', $this->sources->fingerprint($source))->where('is_current', true)->where('status', 'completed')->first();
                if (! $parent && ! $dryRun) {
                    throw new \LogicException('Matching verified source generation is required before retrieval preparation.');
                }
                $key = ['document_content_id' => $contentId, 'profile_hash' => $profile, 'input_fingerprint' => $this->sources->fingerprint($source)];
                $existing = $db->table('document_chunk_sets')->where($key)->first();
                if (! $force && $existing && $existing->status === 'completed') {
                    if (! $dryRun && ! $existing->is_current) {
                        $this->activate($contentId, $existing->id);
                    }

                    return ['content_id' => $contentId, 'document_id' => $source->document_id, 'status' => 'unchanged', 'passages' => $existing->chunk_count, 'source_chunks' => $parent->chunk_count];
                }
                if (! $force && $existing && $existing->attempts >= (int) config('ai.max_attempts')) {
                    return ['content_id' => $contentId, 'document_id' => $source->document_id, 'status' => 'retry_exhausted', 'passages' => 0];
                }
                $extraction = json_decode($source->extraction_metadata, true, flags: JSON_THROW_ON_ERROR);
                $metadata = ['document_id' => $source->document_id, 'document_content_id' => $source->id, 'language' => $source->language, 'title' => $source->title,
                    'source_url' => $extraction['source_url'] ?? $extraction['url'] ?? $source->source_url, 'source_checksum' => $source->content_hash,
                    'quality_status' => $source->quality_status, 'quality_issues' => json_decode($source->quality_issues, true), 'extraction_metadata' => $extraction];
                $chunks = $parent ? $db->table('document_chunks')->where('chunk_set_id', $parent->id)->orderBy('chunk_index')->cursor()
                    : app(LegalDocumentChunker::class)->chunk($source->content, $size, $metadata, config('ai'));
                $passages = $this->engine->build('legal', $source->content, $chunks, $metadata, $this->policy($size));
                $result = ['content_id' => $contentId, 'document_id' => $source->document_id, 'status' => $dryRun ? 'dry_run' : 'completed', 'passages' => count($passages), 'source_chunks' => $parent->chunk_count ?? count($chunks)];
                if ($dryRun) {
                    return $result;
                }
                $values = ['layer' => 'retrieval', 'source_generation_id' => $parent->id, 'source_checksum' => $source->content_hash, 'chunking_version' => config('ai.retrieval_version'),
                    'status' => 'completed', 'chunk_count' => count($passages), 'metadata' => json_encode(['source_bytes' => strlen($source->content), 'source_text_sha256' => hash('sha256', $source->content), 'coverage_verified' => true, 'policy' => $this->policy($size)], JSON_THROW_ON_ERROR),
                    'attempts' => ($existing->attempts ?? 0) + 1, 'last_error_class' => null, 'last_attempt_at' => now(), 'updated_at' => now()];
                if ($existing) {
                    // Successful generations are immutable even under force; verify/reuse them above.
                    if ($existing->status === 'completed') {
                        return ['content_id' => $contentId, 'document_id' => $source->document_id, 'status' => 'unchanged', 'passages' => $existing->chunk_count, 'source_chunks' => $parent->chunk_count];
                    }
                    $setId = $existing->id;
                    $db->table('document_chunk_sets')->where('id', $setId)->update($values);
                } else {
                    $setId = $db->table('document_chunk_sets')->insertGetId([...$key, ...$values, 'is_current' => false, 'created_at' => now()]);
                }
                foreach (array_chunk($passages, 100, true) as $batch) {
                    $rows = [];
                    foreach ($batch as $index => $passage) {
                        $rows[] = [...$passage, 'lineage' => json_encode($passage['lineage'], JSON_THROW_ON_ERROR), 'metadata' => json_encode($passage['metadata'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'chunk_set_id' => $setId, 'document_id' => $source->document_id, 'document_content_id' => $source->id, 'language' => $source->language, 'passage_index' => $index, 'created_at' => now(), 'updated_at' => now()];
                    }$db->table('retrieval_passages')->insert($rows);
                }
                $this->activate($contentId, $setId);

                return $result;
            });
        } catch (Throwable $e) {
            if (! $dryRun) {
                try {
                    $this->failure($contentId, $size, $e);
                } catch (Throwable $recordingError) {
                    Log::error('Retrieval failure could not be recorded', ['document_content_id' => $contentId, 'exception_class' => $recordingError::class]);
                }
            }
            throw $e;
        }
    }

    private function activate(int $contentId, int $setId): void
    {
        $db = $this->guard->connection();
        $db->table('document_chunk_sets')->where('document_content_id', $contentId)->where('layer', 'retrieval')->where('is_current', true)->where('id', '!=', $setId)->update(['is_current' => false]);
        $db->table('document_chunk_sets')->where('id', $setId)->update(['is_current' => true]);
    }

    private function failure(int $contentId, int $size, Throwable $error): void
    {
        $db = $this->guard->connection();
        $db->transaction(function () use ($db, $contentId, $size, $error) {
            $source = $this->sources->sources()->where('c.id', $contentId)->lock('FOR UPDATE OF c,d')->first();
            if (! $source) {
                return;
            }
            $parent = $db->table('document_chunk_sets')->where('document_content_id', $contentId)->where('layer', 'source')->where('is_current', true)->first();
            if (! $parent) {
                return;
            }
            $key = ['document_content_id' => $contentId, 'profile_hash' => $this->profile($size), 'input_fingerprint' => $this->sources->fingerprint($source)];
            $existing = $db->table('document_chunk_sets')->where($key)->first();
            if ($existing && $existing->status === 'completed') {
                return;
            }
            $values = ['layer' => 'retrieval', 'source_generation_id' => $parent->id, 'source_checksum' => $source->content_hash, 'chunking_version' => config('ai.retrieval_version'), 'status' => 'failed', 'chunk_count' => 0,
                'metadata' => '{}', 'attempts' => ($existing->attempts ?? 0) + 1, 'last_error_class' => $error::class, 'last_attempt_at' => now(), 'updated_at' => now(), 'is_current' => false];
            if ($existing) {
                $db->table('document_chunk_sets')->where('id', $existing->id)->update($values);
            } else {
                $db->table('document_chunk_sets')->insert([...$key, ...$values, 'created_at' => now()]);
            }
        });
    }
}
