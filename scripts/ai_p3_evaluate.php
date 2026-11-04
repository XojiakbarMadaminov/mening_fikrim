<?php

use App\Services\Chunking\ChunkingPipeline;
use App\Services\Chunking\PassageGenerationService;
use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\LegalKeywordSearch;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
$dir = $root.'/docs/ai_p3';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
if ($db->getDatabaseName() !== 'mening_fikrim_ai_dev' || app()->runningUnitTests() || app(ChunkingPipeline::class)->status(4000)['pending_retrieval_contents'] !== 0) {
    throw new RuntimeException('Settled isolated full corpus required.');
}
$db->statement("SET application_name='ai-p3-offline-evaluation'");
$db->statement('SET max_parallel_workers_per_gather=0');
if (is_file($dir.'/retrieval_evaluation.json')) {
    throw new RuntimeException('Evaluation evidence exists.');
}
$datasetPath = $dir.'/retrieval_queries.json';
if (is_file($datasetPath)) {
    $dataset = json_decode(file_get_contents($datasetPath), true, flags: JSON_THROW_ON_ERROR);
} else {
    $queries = [];
    $usedQueries = [];
    foreach (['uz-Latn', 'uz-Cyrl', 'ru'] as $language) {
        foreach (['document_title', 'article_heading', 'legal_phrase'] as $type) {
            $query = $db->table('document_chunks as k')->join('document_chunk_sets as s', 's.id', '=', 'k.chunk_set_id')->join('document_contents as c', 'c.id', '=', 'k.document_content_id')
                ->where('s.layer', 'source')->where('s.is_current', true)->where('k.language', $language);
            if ($type === 'article_heading') {
                $query->where('k.section_type', 'article')->whereNotNull('k.section_title');
            } elseif ($type === 'legal_phrase') {
                $query->whereIn('k.section_type', ['paragraph', 'point'])->whereRaw('char_length(k.content)>180');
            } else {
                $query->where('k.chunk_index', 0);
            }
            // Sample one unit per document before LIMIT: a large code must not consume the candidate pool.
            $query->select('k.id', 'k.document_id', 'k.document_content_id', 'k.content', 'k.section_title', 'k.section_type', 'k.section_number', 'k.metadata', 'c.title', 'c.content_hash')
                ->selectRaw('row_number() OVER (PARTITION BY k.document_id ORDER BY md5(k.id::text || ?)) AS sample_rank', [$type]);
            $candidates = $db->query()->fromSub($query, 'candidate')->where('sample_rank', 1)
                ->orderByRaw('md5(document_content_id::text || ?)', [$type])->limit(600)->get();
            $seen = [];
            $chosen = 0;
            foreach ($candidates as $row) {
                if (isset($seen[$row->document_id])) {
                    continue;
                }
                $text = $type === 'document_title' ? $row->title : ($type === 'article_heading' ? $row->section_title : $row->content);
                // Exact observed words only. No inferred law names, numbers or legal assertions.
                preg_match('/[\p{L}][\p{L}\p{M}’‘ʻʼ\x{0027}-]*(?:[ \t]+[\p{L}][\p{L}\p{M}’‘ʻʼ\x{0027}-]*){1,6}/u', $text, $match, PREG_OFFSET_CAPTURE);
                if (! isset($match[0]) || mb_strlen($match[0][0]) > 180) {
                    continue;
                }
                $q = $match[0][0];
                $queryKey = $language.'|'.mb_strtolower($q);
                if (isset($usedQueries[$queryKey])) {
                    continue;
                }
                $offset = strpos($row->content, $q);
                if ($offset === false || ! $db->selectOne("SELECT to_tsvector('simple'::regconfig, ?) @@ plainto_tsquery('simple'::regconfig, ?) AS matches", [$row->content, $q])->matches) {
                    continue;
                }
                $meta = json_decode($row->metadata, true, flags: JSON_THROW_ON_ERROR);
                $start = $meta['start_byte'] + $offset;
                $end = $start + strlen($q);
                $passages = app(PassageGenerationService::class)->currentPassages()->where('p.document_content_id', $row->document_content_id)->where('p.start_byte', '<', $end)->where('p.end_byte', '>', $start)->pluck('p.id')->all();
                if (! $passages) {
                    continue;
                }
                $queries[] = ['id' => 'q'.str_pad(count($queries) + 1, 3, '0', STR_PAD_LEFT), 'query' => $q, 'language' => $language, 'type' => $type,
                    'expected_documents' => [$row->document_id], 'expected_content_id' => $row->document_content_id, 'expected_source_chunk_id' => $row->id,
                    'expected_section' => ['type' => $row->section_type, 'number' => $row->section_number, 'title' => $row->section_title],
                    'expected_span' => ['start_byte' => $start, 'end_byte' => $end], 'expected_passage_ids' => $passages, 'source_checksum' => $row->content_hash,
                    'anchor_sha256' => hash('sha256', $q), 'relevance_label' => 'known_item_exact_source_anchor', 'label_verification' => 'machine_verified_exact_source_anchor', 'human_verified' => false,
                    'verification' => 'Literal query occurs at the recorded source span and its simple lexemes occur in that source chunk. This verifies a known item, not exhaustive semantic relevance.'];
                $seen[$row->document_id] = true;
                $usedQueries[$queryKey] = true;
                if (++$chosen === 10) {
                    break;
                }
            }
            if ($chosen !== 10) {
                throw new RuntimeException('Insufficient real anchors for '.$language.'/'.$type);
            }
        }
    }
    foreach (['uz-Latn', 'uz-Cyrl', 'ru'] as $language) {
        $seeds = array_values(array_filter($queries, fn ($q) => $q['language'] === $language && $q['type'] === 'legal_phrase'));
        foreach ($seeds as $i => $seed) {
            $term = implode(' ', array_slice(explode(' ', $seed['query']), 0, 2));
            $type = ['short_question', 'broad_question', 'paraphrase_candidate', 'multi_document_candidate'][$i % 4];
            $q = match ($language) {
                'uz-Latn' => match ($type) {
                    'short_question' => $term.'?', 'broad_question' => $term.' bo‘yicha qanday tartib bor?', 'paraphrase_candidate' => $term.' haqidagi talablarni tushuntiring', default => $term.' va '.$seeds[($i + 1) % 10]['query']
                },
                'uz-Cyrl' => match ($type) {
                    'short_question' => $term.'?', 'broad_question' => $term.' бўйича қандай тартиб бор?', 'paraphrase_candidate' => $term.' ҳақидаги талабларни тушунтиринг', default => $term.' ва '.$seeds[($i + 1) % 10]['query']
                },
                default => match ($type) {
                    'short_question' => $term.'?', 'broad_question' => 'Какой порядок предусмотрен: '.$term.'?', 'paraphrase_candidate' => 'Объясните требования: '.$term, default => $term.' и '.$seeds[($i + 1) % 10]['query']
                }
            };
            if (isset($usedQueries[$language.'|'.mb_strtolower($q)])) {
                $q = $seed['query'].'?';
            }
            $q = mb_substr($q, 0, 200);
            if (isset($usedQueries[$language.'|'.mb_strtolower($q)])) {
                throw new RuntimeException('Duplicate question template; select another real source anchor.');
            }
            $usedQueries[$language.'|'.mb_strtolower($q)] = true;
            $queries[] = ['id' => 'q'.str_pad(count($queries) + 1, 3, '0', STR_PAD_LEFT), 'query' => mb_substr($q, 0, 200), 'language' => $language, 'type' => $type,
                'expected_documents' => [], 'expected_section' => null, 'expected_span' => null, 'relevance_label' => null, 'label_verification' => 'unverified', 'human_verified' => false,
                'candidate_source_documents' => array_values(array_unique([...$seed['expected_documents'], ...$seeds[($i + 1) % 10]['expected_documents']])),
                'verification' => 'Template question derived from an observed phrase. Human semantic/multi-document relevance judgment is pending; excluded from relevance metrics.'];
        }
    }
    $dataset = ['created_at' => now()->toIso8601String(), 'profile_hash' => app(ChunkingPipeline::class)->profile(4000), 'query_count' => count($queries), 'distinct_language_query_count' => count($usedQueries), 'queries' => $queries,
        'label_policy' => '90 machine-verified source-anchor known items; 30 unverified question/paraphrase/broad/multi-document candidates. No human gold labels. Anchor metrics are silver known-item diagnostics, not semantic recall.'];
    file_put_contents($datasetPath, json_encode($dataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
}
$report = ['at' => now()->toIso8601String(), 'query_count' => count($dataset['queries']), 'label_policy' => $dataset['label_policy'], 'models_called' => 0, 'versions' => []];
$percentile = function (array $numbers, float $p): float {
    sort($numbers);

    return round($numbers[max(0, (int) ceil(count($numbers) * $p) - 1)], 2);
};
foreach (['legal-v2' => false, 'legal-v3' => true] as $version => $enabled) {
    config(['ai.retrieval_enabled' => $enabled]);
    $rows = [];
    $n = $at5 = $at10 = $span10 = 0;
    $reciprocal = 0;
    $latencies = [];
    $timeouts = 0;
    foreach ($dataset['queries'] as $case) {
        $t = microtime(true);
        $error = null;
        try {
            $result = app(LegalKeywordSearch::class)->search($case['query'], $case['language'], 'all', null, 1, 10)['results'];
        } catch (Throwable $e) {
            $error = $e::class;
            $timeouts++;
            $result = [];
        }
        $ms = (microtime(true) - $t) * 1000;
        $latencies[] = $ms;
        $rank = null;
        $spanRank = null;
        $scored = $case['label_verification'] === 'machine_verified_exact_source_anchor';
        if ($scored) {
            // Recheck exact ground truth independently against the immutable source.
            $source = $db->table('document_contents')->where('id', $case['expected_content_id'])->first();
            $span = $case['expected_span'];
            if ($source->content_hash !== $case['source_checksum'] || substr($source->content, $span['start_byte'], $span['end_byte'] - $span['start_byte']) !== $case['query']) {
                throw new RuntimeException('Ground truth source no longer matches.');
            }
            $n++;
            foreach ($result as $i => $item) {
                if ($rank === null && in_array($item['document_id'], $case['expected_documents'], true)) {
                    $rank = $i + 1;
                }
                $bounds = $enabled ? $item : ($item['metadata'] ?? []);
                if ($spanRank === null && $item['document_content_id'] === $case['expected_content_id'] && ($bounds['start_byte'] ?? PHP_INT_MAX) < $span['end_byte'] && ($bounds['end_byte'] ?? -1) > $span['start_byte']) {
                    $spanRank = $i + 1;
                }
            }
            $at5 += (int) ($rank !== null && $rank <= 5);
            $at10 += (int) ($rank !== null);
            $span10 += (int) ($spanRank !== null);
            $reciprocal += $rank === null ? 0 : 1 / $rank;
        }
        $rows[] = ['query_id' => $case['id'], 'scored' => $scored, 'known_document_rank' => $rank, 'source_span_rank' => $spanRank, 'latency_ms' => round($ms, 2),
            'returned_ids' => array_column($result, 'id'), 'returned_document_ids' => array_column($result, 'document_id'), 'error' => $error];
        usleep(10000);
    }
    $report['versions'][$version] = ['scored_machine_anchor_queries' => $n, 'unverified_excluded' => count($rows) - $n, 'recall_at_5' => $at5 / $n, 'recall_at_10' => $at10 / $n, 'mrr_at_10' => $reciprocal / $n,
        'source_span_hit_at_10' => $span10 / $n, 'latency_p50_ms' => $percentile($latencies, .5), 'latency_p95_ms' => $percentile($latencies, .95), 'latency_max_ms' => max($latencies), 'errors' => $timeouts, 'results' => $rows];
}
config(['ai.retrieval_enabled' => true]);
$report['limitations'] = 'Exact-anchor known-item labels are machine verified, not human gold or exhaustive legal relevance. Unverified semantic and multi-document candidates have latency/results only. PostgreSQL simple lexemes have no morphology, semantic equivalence or reranking; deterministic ID ordering is the baseline.';
file_put_contents($dir.'/retrieval_evaluation.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(array_map(fn ($v) => array_diff_key($v, ['results' => true]), $report['versions']), JSON_PRETTY_PRINT)."\n";
