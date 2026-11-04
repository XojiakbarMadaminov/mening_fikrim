<?php

use App\Services\Chunking\ChunkingPipeline;
use App\Services\Chunking\PassageGenerationService;
use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\LegalKeywordSearch;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$root = dirname(__DIR__);
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
if ($db->getDatabaseName() !== 'mening_fikrim_ai_dev' || app()->runningUnitTests() || app(ChunkingPipeline::class)->status(4000)['pending_retrieval_contents'] !== 0) {
    throw new RuntimeException('Settled isolated full corpus required.');
}
$db->statement('SET max_parallel_workers_per_gather=0');
$search = app(LegalKeywordSearch::class);
$report = ['at' => now()->toIso8601String(), 'source_requests' => 0, 'checks' => [], 'errors' => []];
foreach ([['mehnat', 'uz-Latn', 'eligible', null], ['huquq', 'uz-Latn', 'eligible', 'Закон'], ['меҳнат', 'uz-Cyrl', 'eligible', null], ['ҳуқуқ', 'uz-Cyrl', 'all', null], ['труд', 'ru', 'eligible', null], ['право', 'ru', 'review', null]] as [$query, $language, $quality, $category]) {
    $t = microtime(true);
    try {
        $result = $search->search($query, $language, $quality, $category, 1, 5);
        $page2 = $search->search($query, $language, $quality, $category, 2, 5);
        if ($result['results'] === [] || array_intersect(array_column($result['results'], 'id'), array_column($page2['results'], 'id'))) {
            $report['errors'][] = ['query' => $query, 'reason' => 'empty_or_overlapping_pages'];
        }
        foreach ([...$result['results'], ...$page2['results']] as $row) {
            $source = $db->table('document_contents')->where('id', $row['document_content_id'])->first();
            $p = $db->table('retrieval_passages')->where('id', $row['passage_id'])->first();
            if ($row['chunking_version'] !== config('ai.retrieval_version') || $row['source_chunking_version'] !== 'legal-v2'
                || $source->document_id !== $row['document_id'] || $source->language !== $language || ($quality !== 'all' && $row['suitability'] !== $quality)
                || ($category !== null && $row['document_type'] !== $category) || $source->content_hash !== $row['source_checksum']
                || hash('sha256', $p->body) !== $row['body_hash'] || substr($source->content, $row['start_byte'], $row['end_byte'] - $row['start_byte']) !== $p->body
                || $row['source_chunk_ids'] === [] || ! app(PassageGenerationService::class)->currentPassages()->where('p.id', $p->id)->exists()) {
                $report['errors'][] = ['id' => $p->id, 'reason' => 'identity_filter_span_or_profile'];
            }
        }
        $report['checks'][] = ['query' => $query, 'language' => $language, 'quality' => $quality, 'category' => $category, 'two_pages_ms' => round((microtime(true) - $t) * 1000, 2),
            'page1_ids' => array_column($result['results'], 'id'), 'page2_ids' => array_column($page2['results'], 'id'), 'next_page' => $result['next_page'], 'first_result' => $result['results'][0] ?? null];
    } catch (Throwable $e) {
        $report['errors'][] = ['query' => $query, 'class' => $e::class];
    }
}
$literal = "x' OR 1=1 --";
$injection = $search->search($literal, quality: 'all')['results'];
$report['injection_results'] = count($injection);
$report['injection_treated_as_literal_lexemes'] = true;
foreach ($injection as $item) {
    $body = $db->table('retrieval_passages')->where('id', $item['id'])->value('body');
    if (! $db->selectOne("SELECT to_tsvector('simple'::regconfig, ?) @@ plainto_tsquery('simple'::regconfig, ?) AS matches", [$body, $literal])->matches) {
        $report['injection_treated_as_literal_lexemes'] = false;
    }
}
$report['injection_note'] = 'SQL-shaped text is bound as a literal plainto_tsquery. Real table/math text can contain x, OR and 1; legitimate lexical matches are not SQL execution.';
$report['index_probes'] = [];
foreach (['fuqarolar', 'ҳуқуқ', 'порядок'] as $query) {
    $t = microtime(true);
    $result = $search->search($query, quality: 'all');
    $report['index_probes'][] = ['query' => $query, 'ms' => round((microtime(true) - $t) * 1000, 2), 'results' => count($result['results'])];
}
$report['explain'] = $db->select("EXPLAIN (FORMAT JSON) SELECT id FROM retrieval_passages WHERE to_tsvector('simple'::regconfig,body) @@ plainto_tsquery('simple'::regconfig, ?)", ['fuqarolar']);
$report['passed'] = $report['errors'] === [] && $report['injection_treated_as_literal_lexemes'] && $report['injection_results'] <= 10;
$path = $root.'/docs/ai_p3/search_acceptance.json';
if (is_file($path)) {
    throw new RuntimeException('Search acceptance evidence exists.');
}
file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['passed' => $report['passed'], 'errors' => $report['errors'], 'index_probes' => $report['index_probes']], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
exit($report['passed'] ? 0 : 1);
