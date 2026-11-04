<?php

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\LegalKeywordSearch;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$guard = app(AiDatabaseGuard::class);
$db = $guard->connection();
$search = app(LegalKeywordSearch::class);
$report = ['at' => now()->toIso8601String(), 'source_requests' => 0, 'checks' => [], 'errors' => []];
foreach ([['mehnat', 'uz-Latn', 'structured', null], ['huquq', 'uz-Latn', 'structured', 'Закон'], ['меҳнат', 'uz-Cyrl', 'structured', null], ['ҳуқуқ', 'uz-Cyrl', 'all', null], ['труд', 'ru', 'structured', null], ['право', 'ru', 'needs_review', null]] as [$query,$language,$quality,$category]) {
    $start = microtime(true);
    try {
        $result = $search->search($query, $language, $quality, $category, 1, 5);
    } catch (Throwable $e) {
        $report['errors'][] = ['query' => $query, 'language' => $language, 'quality' => $quality, 'error_class' => $e::class, 'code' => $e->getCode()];

        continue;
    }
    $seconds = microtime(true) - $start;
    if ($result['results'] === []) {
        $report['errors'][] = ['query' => $query, 'reason' => 'no_results'];
    }
    foreach ($result['results'] as $row) {
        $source = $db->table('document_contents')->where('id', $row['document_content_id'])->first();
        $chunk = $db->table('document_chunks')->where('id', $row['id'])->first();
        if ($source->language !== $language || ($quality !== 'all' && $row['quality_status'] !== $quality)
            || $source->content_hash !== $row['source_checksum'] || hash('sha256', $chunk->content) !== $row['content_hash']) {
            $report['errors'][] = ['chunk_id' => $row['id'], 'reason' => 'attribution_or_filter'];
        }
    }
    $report['checks'][] = ['query' => $query, 'language' => $language, 'quality' => $quality, 'category' => $category, 'seconds' => round($seconds, 6), 'returned' => count($result['results']), 'next_page' => $result['next_page'], 'result_ids' => array_column($result['results'], 'id'), 'first_result' => $result['results'][0] ?? null];
}
$report['passed'] = $report['errors'] === [];
file_put_contents(dirname(__DIR__).'/docs/ai_p2/search_acceptance.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['passed' => $report['passed'], 'checks' => count($report['checks']), 'seconds' => array_column($report['checks'], 'seconds'), 'errors' => $report['errors']])."\n";
exit($report['passed'] ? 0 : 1);
