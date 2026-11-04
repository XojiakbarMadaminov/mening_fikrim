<?php

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\LegalKeywordSearch;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
$db->statement('SET max_parallel_workers_per_gather=0');
$r = ['at' => now()->toIso8601String(), 'passages' => $db->table('retrieval_passages')->count(), 'indexes' => $db->select("SELECT tablename,indexname,indexdef FROM pg_indexes WHERE tablename IN ('document_chunks','retrieval_passages')"), 'probes' => []];
foreach (['fuqarolar', 'ҳуқуқ', 'порядок'] as $q) {
    $t = microtime(true);
    try {
        $x = app(LegalKeywordSearch::class)->search($q, quality: 'all');
        $r['probes'][] = ['query' => $q, 'ms' => round((microtime(true) - $t) * 1000, 2), 'results' => count($x['results'])];
    } catch (Throwable $e) {
        $r['probes'][] = ['query' => $q, 'ms' => round((microtime(true) - $t) * 1000, 2), 'error' => $e::class];
    }
}
$r['explain'] = $db->select("EXPLAIN (FORMAT JSON) SELECT id FROM retrieval_passages WHERE to_tsvector('simple'::regconfig,body) @@ plainto_tsquery('simple'::regconfig, ?)", ['fuqarolar']);
$file = dirname(__DIR__).'/docs/ai_p3/index_before.json';
if (is_file($file)) {
    throw new RuntimeException('Evidence exists.');
}
file_put_contents($file, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($r, JSON_UNESCAPED_UNICODE)."\n";
