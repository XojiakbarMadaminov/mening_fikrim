<?php

/** Offline re-extraction of freshly captured real P3 PDF bytes. No HTTP or database writes. */
use App\Scraping\LexUz\PdfQualityInspector;
use App\Scraping\LexUz\PdfTextExtractor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
require dirname(__DIR__, 2).'/tests/bootstrap.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active_test') {
    throw new RuntimeException('Fixed testing environment required.');
}
$app->make(Factory::class)->preventStrayRequests();
$temp = trim(file_get_contents(storage_path('app/p3_live_path')));
$before = json_decode(file_get_contents(__DIR__.'/alternate_pdf_regression.json'), true, flags: JSON_THROW_ON_ERROR);
$results = [];
foreach ($before as $id => $record) {
    $quality = $record['quality'];
    $hash = $quality['provenance']['source_sha256'];
    $file = $temp.'/'.$hash.'.pdf';
    if (! is_file($file) || hash_file('sha256', $file) !== $hash) {
        throw new RuntimeException('Captured PDF provenance mismatch.');
    }
    $candidate = json_decode(file_get_contents($temp.'/'.$id.'.candidate.json'), true, flags: JSON_THROW_ON_ERROR);
    $row = $candidate['metadata']['lex']['source_card']['accepting_bodies'][0];
    $parsed = app(PdfTextExtractor::class)->extract(file_get_contents($file), 'application/pdf', $quality['provenance']['declared_language'], ['document_number' => $row[5], 'issued_date' => $row[4]]);
    $gold = $record['gold'];
    $page = explode('<!-- page '.$gold['page'].' -->', $parsed['content'])[1] ?? '';
    $page = preg_replace('/\s+/u', ' ', explode('<!-- page ', $page)[0]);
    $pattern = (string) $id === '-8553692' ? '/4[.,]?\s+Ушбу қарор.*?кучга киради[.]?/u' : '/39[.,]?\s+Ушбу Низом.*?жавоб беради[.]?/u';
    $matched = preg_match($pattern, $page, $excerpt);
    $results[$id] = ['offline_replay_of_fresh_real_pdf' => true, 'resource_url' => $record['resource_url'], 'source_sha256' => $hash,
        'markdown_hash_unchanged' => $parsed['quality']['provenance']['markdown_sha256'] === $quality['provenance']['markdown_sha256'],
        'quality' => $parsed['quality'], 'gold' => $matched ? ['page' => $gold['page'], 'reference' => $gold['reference'], 'observed' => $excerpt[0], 'metrics' => app(PdfQualityInspector::class)->compareGold($gold['reference'], $excerpt[0])] : ['matched' => false]];
    echo $id.' offline region diagnostics complete, pages='.$parsed['quality']['pages_extracted']."\n";
    flush();
}
file_put_contents(__DIR__.'/ocr_regions_real.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
