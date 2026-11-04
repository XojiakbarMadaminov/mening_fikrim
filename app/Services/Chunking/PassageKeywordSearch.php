<?php

namespace App\Services\Chunking;

use App\Services\Legal\AiDatabaseGuard;
use InvalidArgumentException;

class PassageKeywordSearch
{
    public function __construct(private AiDatabaseGuard $guard, private PassageGenerationService $passages) {}

    public function search(string $query, ?string $language = null, string $quality = 'structured', ?string $category = null, int $page = 1, int $limit = 10): array
    {
        $db = $this->guard->connection();
        $suitability = match ($quality) {
            'structured' => 'eligible','needs_review' => 'review',default => $quality
        };
        if (! mb_check_encoding($query, 'UTF-8') || str_contains($query, "\0") || mb_strlen(trim($query)) < 2 || mb_strlen($query) > 200
            || ! in_array($suitability, ['eligible', 'review', 'blocked', 'all'], true) || ($language !== null && ! in_array($language, ['uz-Latn', 'uz-Cyrl', 'ru', 'en'], true))
            || ($category !== null && mb_strlen($category) > 100) || $page < 1 || $page > 1000 || $limit < 1 || $limit > 50) {
            throw new InvalidArgumentException('Invalid bounded passage keyword query or filter.');
        }

        return $db->transaction(function () use ($db, $query, $language, $quality, $suitability, $category, $page, $limit) {
            if ($db->transactionLevel() === 1) {
                $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            }
            $db->statement("SET LOCAL statement_timeout='5s'");
            $matches = $db->table('retrieval_passages as matched')->select('matched.id', 'matched.chunk_set_id', 'matched.document_id')
                ->whereRaw("to_tsvector('simple'::regconfig,matched.body) @@ plainto_tsquery('simple'::regconfig, ?)", [$query]);
            if ($language !== null) {
                $matches->where('matched.language', $language);
            }
            if ($suitability !== 'all') {
                $matches->where('matched.suitability', $suitability);
            }
            $candidate = $db->table('passage_matches as m')->join('document_chunk_sets as r', 'r.id', '=', 'm.chunk_set_id')
                ->where('r.layer', 'retrieval')->where('r.is_current', true)->where('r.status', 'completed')->where('r.profile_hash', $this->passages->profile((int) config('ai.chunk_size')))
                ->select('m.id')->orderBy('m.id')->offset(($page - 1) * $limit)->limit($limit + 1);
            if ($category !== null) {
                $candidate->join('documents as d', 'd.id', '=', 'm.document_id')->where('d.document_type', $category);
            }
            $sql = 'WITH passage_matches AS MATERIALIZED ('.$matches->toSql().') '.$candidate->toSql();
            $ids = collect($db->select($sql, array_merge($matches->getBindings(), $candidate->getBindings())))->pluck('id');
            $rows = $ids->isEmpty() ? collect() : $this->passages->currentPassages()->whereIn('p.id', $ids->take($limit)->all())->orderBy('p.id')
                ->select('p.id', 'p.document_id', 'p.document_content_id', 'p.language', 'p.passage_index', 'p.body_hash', 'p.start_byte', 'p.end_byte', 'p.start_char', 'p.end_char', 'p.suitability', 'p.lineage', 'p.metadata',
                    'r.chunking_version', 'r.profile_hash', 'r.source_checksum', 's.chunking_version as source_chunking_version', 'c.title as document_title', 'c.quality_status as source_quality_status', 'd.document_type', 'd.status as legal_status')
                ->selectRaw('left(p.body,600) AS excerpt')->get();
            $items = $rows->map(function ($row) {
                $row->metadata = json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);
                $row->lineage = json_decode($row->lineage, true, flags: JSON_THROW_ON_ERROR);
                $row->passage_id = $row->id;
                $row->source_url = $row->metadata['source_url'];
                $row->content_hash = $row->body_hash;
                $row->quality_status = $row->suitability;
                $row->source_chunk_ids = array_values(array_unique(array_column($row->lineage, 'source_chunk_id')));

                return (array) $row;
            })->all();

            return ['query' => $query, 'layer' => 'retrieval_passage', 'method' => 'postgresql_simple_lexemes_no_stemming', 'chunking_version' => config('ai.retrieval_version'),
                'profile_hash' => $this->passages->profile((int) config('ai.chunk_size')), 'language' => $language, 'quality' => $quality, 'suitability' => $suitability,
                'page' => $page, 'limit' => $limit, 'next_page' => $page < 1000 && $ids->count() > $limit ? $page + 1 : null, 'results' => $items];
        });
    }
}
