<?php

namespace App\Services\Legal;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

class ChunkDocumentService
{
    public function __construct(private AiDatabaseGuard $guard, private LegalDocumentChunker $chunker) {}

    public function profile(int $size): string
    {
        if ($size < 200 || $size > 32000 || (int) config('ai.overlap_chars') !== 0 || (int) config('ai.max_chunk_size') < 200) {
            throw new \InvalidArgumentException('Chunk size must be 200..32000; maximum >=200; this exact-text profile requires zero overlap.');
        }

        return hash('sha256', json_encode(['version' => config('ai.version'), 'size' => $size, 'overlap' => 0,
            'maximum' => (int) config('ai.max_chunk_size'), 'structure' => (bool) config('ai.structure_splitting')], JSON_THROW_ON_ERROR));
    }

    public function fingerprint(object $content): string
    {
        return hash('sha256', json_encode([
            $content->content, $content->content_hash, $content->document_id, $content->language, $content->title,
            $content->quality_status, $content->quality_issues, $content->extraction_metadata, $content->source_url,
        ], JSON_THROW_ON_ERROR));
    }

    public function sources(): Builder
    {
        return $this->guard->connection()->table('document_contents as c')->join('documents as d', 'd.id', '=', 'c.document_id')
            ->select('c.*', 'd.source_url');
    }

    public function pendingSources(int $size): Builder
    {
        return $this->sources()->whereNotExists(function ($query) use ($size) {
            $query->selectRaw('1')->from('document_chunk_sets as s')->whereColumn('s.document_content_id', 'c.id')
                ->where('s.profile_hash', $this->profile($size))->where('s.is_current', true)
                ->whereIn('s.status', ['completed', 'empty'])->whereColumn('s.source_checksum', 'c.content_hash');
        });
    }

    public function currentChunks(?int $size = null): Builder
    {
        $size ??= (int) config('ai.chunk_size');

        return $this->guard->connection()->table('document_chunks as k')
            ->join('document_chunk_sets as s', 's.id', '=', 'k.chunk_set_id')
            ->join('document_contents as c', 'c.id', '=', 's.document_content_id')
            ->join('documents as d', 'd.id', '=', 'c.document_id')
            ->where('s.is_current', true)->where('s.status', 'completed')->where('s.profile_hash', $this->profile($size))
            ->whereColumn('s.source_checksum', 'c.content_hash')->whereColumn('k.source_checksum', 's.source_checksum')
            ->whereColumn('k.document_content_id', 'c.id')->whereColumn('k.document_id', 'd.id')->whereColumn('k.language', 'c.language')
            ->whereColumn('k.chunking_version', 's.chunking_version');
    }

    public function isCurrent(object $source, int $size): bool
    {
        return $this->guard->connection()->table('document_chunk_sets')
            ->where('document_content_id', $source->id)->where('profile_hash', $this->profile($size))->where('is_current', true)
            ->where('input_fingerprint', $this->fingerprint($source))->whereIn('status', ['completed', 'empty'])->exists();
    }

    public function status(int $size): array
    {
        $db = $this->guard->connection();
        $profile = $this->profile($size);
        $sets = $db->table('document_chunk_sets')->where('profile_hash', $profile);
        $active = (clone $sets)->where('is_current', true)->whereIn('status', ['completed', 'empty']);
        $failed = (clone $sets)->where('status', 'failed');
        $failedContents = 0;
        $exhaustedContents = 0;
        foreach ((clone $failed)->cursor() as $failure) {
            $source = $this->sources()->where('c.id', $failure->document_content_id)->first();
            if ($source && $failure->input_fingerprint === $this->fingerprint($source)) {
                $failedContents++;
                $exhaustedContents += (int) ($failure->attempts >= (int) config('ai.max_attempts'));
            }
        }

        return [
            'database' => $db->getDatabaseName(), 'documents' => $db->table('documents')->count(),
            'contents' => $db->table('document_contents')->count(), 'processed_contents' => (clone $active)->count(),
            'processed_documents' => (clone $active)->join('document_contents as c', 'c.id', '=', 'document_content_id')->distinct()->count('c.document_id'),
            'pending_contents' => $this->pendingSources($size)->count(), 'failed_contents' => $failedContents, 'pending_includes_failed' => true,
            'historical_failed_generations' => (clone $failed)->count(),
            'retry_exhausted_contents' => $exhaustedContents,
            'source_needs_review' => $db->table('document_contents')->where('quality_status', 'needs_review')->count(),
            'sets' => (clone $sets)->count(), 'current_chunks' => $this->currentChunks($size)->count(),
            'chunks' => $db->table('document_chunks')->count(), 'queued' => $db->table('ai_jobs')->count(),
            'profile_hash' => $profile, 'chunking_version' => config('ai.version'),
            'versions' => $db->table('document_chunk_sets')->selectRaw('chunking_version,count(*) AS sets')->groupBy('chunking_version')->get()->all(),
        ];
    }

    public function process(int $contentId, int $size, bool $force = false, bool $dryRun = false): array
    {
        $db = $this->guard->connection();
        $profile = $this->profile($size);
        try {
            return $db->transaction(function () use ($db, $contentId, $size, $force, $dryRun, $profile) {
                // Also lock the document: URL changes cannot race generation activation.
                $source = $this->sources()->where('c.id', $contentId)->lock('FOR UPDATE OF c, d')->first();
                if (! $source) {
                    throw new \RuntimeException('Source content not found: '.$contentId);
                }
                $fingerprint = $this->fingerprint($source);
                $setQuery = $db->table('document_chunk_sets')->where('document_content_id', $contentId)
                    ->where('profile_hash', $profile)->where('input_fingerprint', $fingerprint);
                $existing = (clone $setQuery)->first();
                if (! $force && $existing && in_array($existing->status, ['completed', 'empty'], true)) {
                    if (! $dryRun && ! $existing->is_current) {
                        $this->activate($contentId, $existing->id);
                    }

                    return ['content_id' => $contentId, 'document_id' => $source->document_id, 'status' => 'unchanged', 'chunks' => $existing->chunk_count];
                }
                if (! $force && $existing && $existing->attempts >= (int) config('ai.max_attempts')) {
                    return ['content_id' => $contentId, 'document_id' => $source->document_id, 'status' => 'retry_exhausted', 'chunks' => 0];
                }
                $extraction = json_decode($source->extraction_metadata, true, flags: JSON_THROW_ON_ERROR);
                $chunks = $this->chunker->chunk($source->content, $size, [
                    'quality_status' => $source->quality_status,
                    'quality_issues' => json_decode($source->quality_issues, true, flags: JSON_THROW_ON_ERROR),
                    'extraction_metadata' => $extraction,
                ], config('ai'));
                $result = ['content_id' => $contentId, 'document_id' => $source->document_id,
                    'status' => $dryRun ? 'dry_run' : ($chunks === [] ? 'empty' : 'completed'), 'chunks' => count($chunks)];
                if ($dryRun) {
                    return $result;
                }
                $metadata = ['source_text_sha256' => hash('sha256', $source->content), 'source_bytes' => strlen($source->content),
                    'chunk_size' => $size, 'maximum_characters' => config('ai.max_chunk_size'), 'coverage_verified' => true, 'overlap_bytes' => 0];
                $set = ['source_checksum' => $source->content_hash, 'input_fingerprint' => $fingerprint, 'chunking_version' => config('ai.version'),
                    'status' => $result['status'], 'chunk_count' => count($chunks), 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    'attempts' => ($existing->attempts ?? 0) + 1, 'last_error_class' => null, 'last_attempt_at' => now(), 'updated_at' => now()];
                if ($existing) {
                    $setId = $existing->id;
                    (clone $setQuery)->update($set);
                    // Only an explicit force can replace an already successful exact generation.
                    $db->table('document_chunks')->where('chunk_set_id', $setId)->delete();
                } else {
                    $setId = $db->table('document_chunk_sets')->insertGetId([...$set, 'document_content_id' => $contentId,
                        'profile_hash' => $profile, 'is_current' => false, 'created_at' => now()]);
                }
                $sourceUrl = $extraction['source_url'] ?? $extraction['url'] ?? $source->source_url;
                foreach (array_chunk($chunks, 100, true) as $batch) {
                    $rows = [];
                    foreach ($batch as $index => $chunk) {
                        $rows[] = [...$chunk, 'metadata' => json_encode($chunk['metadata'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'chunk_set_id' => $setId, 'document_id' => $source->document_id, 'document_content_id' => $contentId, 'chunk_index' => $index,
                            'language' => $source->language, 'source_url' => $sourceUrl, 'source_checksum' => $source->content_hash,
                            'chunking_version' => config('ai.version'), 'created_at' => now(), 'updated_at' => now()];
                    }
                    $db->table('document_chunks')->insert($rows);
                }
                $this->activate($contentId, $setId);

                return $result;
            });
        } catch (Throwable $e) {
            if (! $dryRun) {
                $this->recordFailure($contentId, $size, $e);
            }
            throw $e;
        }
    }

    private function activate(int $contentId, int $setId): void
    {
        $db = $this->guard->connection();
        $db->table('document_chunk_sets')->where('document_content_id', $contentId)->where('layer', 'source')->where('is_current', true)->where('id', '!=', $setId)->update(['is_current' => false]);
        $db->table('document_chunk_sets')->where('id', $setId)->update(['is_current' => true]);
    }

    private function recordFailure(int $contentId, int $size, Throwable $error): void
    {
        try {
            $db = $this->guard->connection();
            $db->transaction(function () use ($db, $contentId, $size, $error) {
                $source = $this->sources()->where('c.id', $contentId)->lock('FOR UPDATE OF c, d')->first();
                if (! $source) {
                    return;
                }
                $key = ['document_content_id' => $contentId, 'profile_hash' => $this->profile($size), 'input_fingerprint' => $this->fingerprint($source)];
                $existing = $db->table('document_chunk_sets')->where($key)->first();
                $values = ['attempts' => ($existing->attempts ?? 0) + 1, 'last_error_class' => $error::class, 'last_attempt_at' => now(), 'updated_at' => now()];
                if ($existing) {
                    $db->table('document_chunk_sets')->where('id', $existing->id)->update($values);
                } else {
                    $db->table('document_chunk_sets')->insert([...$key, ...$values, 'source_checksum' => $source->content_hash,
                        'chunking_version' => config('ai.version'), 'status' => 'failed', 'chunk_count' => 0,
                        'metadata' => '{}', 'is_current' => false, 'created_at' => now()]);
                }
            });
        } catch (Throwable $e) {
            Log::error('Could not record legal chunk failure', ['content_id' => $contentId, 'exception_class' => $e::class]);
        }
    }
}
