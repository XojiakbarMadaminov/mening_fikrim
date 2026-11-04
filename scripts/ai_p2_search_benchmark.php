<?php

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\ChunkDocumentService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
$db->beginTransaction();
$db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
$db->statement('SET LOCAL max_parallel_workers_per_gather = 0');
$db->statement("SET LOCAL statement_timeout = '5s'");
$service = app(ChunkDocumentService::class);
$report = ['at' => now()->toIso8601String(), 'current_chunks' => $service->currentChunks()->count(), 'plans' => []];
foreach (['on', 'off'] as $enabled) {
    $db->beginTransaction();
    try {
        $db->statement('SET LOCAL enable_bitmapscan = '.$enabled);
        $lexical = $db->table('document_chunks as matched')->select('matched.id', 'matched.chunk_set_id')
            ->whereRaw("to_tsvector('simple'::regconfig,matched.content) @@ plainto_tsquery('simple'::regconfig, ?)", ['mehnat'])
            ->where('matched.quality_status', 'structured')->where('matched.language', 'uz-Latn');
        $q = $db->table('keyword_matches as m')->join('document_chunk_sets as s', 's.id', '=', 'm.chunk_set_id')
            ->where('s.is_current', true)->where('s.status', 'completed')->where('s.profile_hash', $service->profile((int) config('ai.chunk_size')))
            ->select('m.id')->orderBy('m.id')->limit(10);
        $sql = 'WITH keyword_matches AS MATERIALIZED ('.$lexical->toSql().') '.$q->toSql();
        $bindings = array_merge($lexical->getBindings(), $q->getBindings());
        $plan = $db->select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$sql, $bindings);
        $decoded = json_decode($plan[0]->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR)[0];
        $report['plans'][$enabled] = ['execution_ms' => $decoded['Execution Time'], 'planning_ms' => $decoded['Planning Time'], 'plan' => $decoded['Plan'], 'result_ids' => array_map(fn ($row) => $row->id, $db->select($sql, $bindings))];
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        $report['plans'][$enabled] = ['error_class' => $e::class, 'sqlstate' => $e->getCode(), 'timeout_bound_seconds' => 5, 'completion_time_unavailable' => true];
    }
}
$db->rollBack();
$report['same_results'] = isset($report['plans']['on']['result_ids'], $report['plans']['off']['result_ids'])
    ? $report['plans']['on']['result_ids'] === $report['plans']['off']['result_ids'] : null;
if ($report['same_results'] === false) {
    throw new RuntimeException('Search plans disagreed on the same snapshot.');
}
file_put_contents(dirname(__DIR__).'/docs/ai_p2/search_index_comparison.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['current_chunks' => $report['current_chunks'], 'enabled_ms' => $report['plans']['on']['execution_ms'] ?? null, 'disabled_ms' => $report['plans']['off']['execution_ms'] ?? null, 'errors' => array_filter($report['plans'], fn ($p) => isset($p['error_class']))])."\n";
