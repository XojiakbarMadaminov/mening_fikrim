<?php

namespace App\Services\Legal;

use App\Services\Chunking\PassageKeywordSearch;
use InvalidArgumentException;

class LegalKeywordSearch
{
    public function __construct(private ChunkDocumentService $chunks, private AiDatabaseGuard $guard) {}

    public function search(string $query, ?string $language = null, string $quality = 'structured', ?string $category = null, int $page = 1, int $limit = 10): array
    {
        if (config('ai.retrieval_enabled')) {
            return app(PassageKeywordSearch::class)->search($query, $language, $quality, $category, $page, $limit);
        }
        $db = $this->guard->connection();
        if (! mb_check_encoding($query, 'UTF-8') || str_contains($query, "\0") || mb_strlen(trim($query)) < 2 || mb_strlen($query) > 200 || $page < 1 || $page > 1000 || $limit < 1 || $limit > 50
            || ! in_array($quality, ['structured', 'needs_review', 'all'], true)
            || ($language !== null && ! in_array($language, ['uz-Latn', 'uz-Cyrl', 'ru', 'en'], true)) || ($category !== null && mb_strlen($category) > 100)) {
            throw new InvalidArgumentException('Invalid search: query 2..200 chars, page 1..1000, limit 1..50, known language/quality and bounded category.');
        }
        // Keep lexical candidate selection narrow: wide JSON/source projections can cause a bad LIMIT plan.
        [$rows, $hasMore] = $db->transaction(function () use ($db, $query, $language, $quality, $category, $page, $limit) {
            if ($db->transactionLevel() === 1) {
                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $db->statement("SET LOCAL statement_timeout = '5s'");
            // Materialize lexical matches before current-source joins/LIMIT, preventing a primary-key scan
            // that repeatedly tokenizes nonmatching wide text. Values remain bound in both query parts.
            $lexical = $db->table('document_chunks as matched')->select('matched.id', 'matched.chunk_set_id', 'matched.document_id')
                ->whereRaw("to_tsvector('simple'::regconfig, matched.content) @@ plainto_tsquery('simple'::regconfig, ?)", [$query]);
            if ($language !== null) {
                $lexical->where('matched.language', $language);
            }
            if ($quality !== 'all') {
                $lexical->where('matched.quality_status', $quality);
            }
            // Candidate eligibility is the durable current ledger, avoiding redundant checksum joins
            // that PostgreSQL can misestimate. Hydration below applies every source-identity guard.
            $candidates = $db->table('keyword_matches as m')->join('document_chunk_sets as s', 's.id', '=', 'm.chunk_set_id')
                ->where('s.is_current', true)->where('s.status', 'completed')
                ->where('s.profile_hash', $this->chunks->profile((int) config('ai.chunk_size')))->select('m.id')
                ->orderBy('m.id')->offset(($page - 1) * $limit)->limit($limit + 1);
            if ($category !== null) {
                $candidates->join('documents as d', 'd.id', '=', 'm.document_id')->where('d.document_type', $category);
            }
            $sql = 'WITH keyword_matches AS MATERIALIZED ('.$lexical->toSql().') '.$candidates->toSql();
            $ids = collect($db->select($sql, array_merge($lexical->getBindings(), $candidates->getBindings())))->pluck('id');
            $hasMore = $ids->count() > $limit;
            if ($ids->isEmpty()) {
                return [collect(), false];
            }
            $rows = $this->chunks->currentChunks()->whereIn('k.id', $ids->take($limit)->all())
                ->select('k.id', 'k.document_id', 'k.document_content_id', 'k.chunk_index', 'k.section_type', 'k.section_number', 'k.section_title',
                    'k.language', 'k.source_url', 'k.content_hash', 'k.source_checksum', 'k.chunking_version', 'k.quality_status', 'k.metadata',
                    'c.title as document_title', 'd.document_type', 'd.status as legal_status')
                ->selectRaw('left(k.content, 600) AS excerpt')->orderBy('k.id')->get();

            return [$rows, $hasMore];
        });
        $items = $rows->take($limit)->map(function ($row) {
            $row->metadata = json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);

            return (array) $row;
        })->all();

        return ['query' => $query, 'method' => 'postgresql_simple_lexemes_no_stemming', 'profile_hash' => $this->chunks->profile((int) config('ai.chunk_size')),
            'language' => $language, 'quality' => $quality, 'page' => $page, 'limit' => $limit, 'next_page' => $hasMore ? $page + 1 : null, 'results' => $items];
    }
}
