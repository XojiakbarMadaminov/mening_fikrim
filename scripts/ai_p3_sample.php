<?php

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\ChunkDocumentService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
$source = app(ChunkDocumentService::class);
$file = dirname(__DIR__).'/docs/ai_p3/pilot_sample.json';
if (is_file($file)) {
    throw new RuntimeException('Pilot sample is fixed already.');
}
$reasons = [];
$add = function ($ids, $reason) use (&$reasons) {
    foreach ($ids as $id) {
        $reasons[$id][] = $reason;
    }
};
foreach (['uz-Latn', 'uz-Cyrl', 'ru', 'en'] as $lang) {
    $add($source->sources()->where('c.language', $lang)->orderBy('c.id')->limit(2)->pluck('c.id')->all(), 'language:'.$lang);
}
foreach (['Кодекс', 'Закон', 'Постановление', 'Решение', 'Указ', 'Приказ', 'Положение'] as $type) {
    foreach (['uz-Latn', 'uz-Cyrl', 'ru'] as $lang) {
        $add($source->sources()->where('d.document_type', $type)->where('c.language', $lang)->orderBy('c.id')->limit(1)->pluck('c.id')->all(), 'type:'.$type.':'.$lang);
    }
}
foreach (['pdf', 'ocr'] as $format) {
    foreach (['uz-Latn', 'uz-Cyrl'] as $lang) {
        $add($source->sources()->where('c.language', $lang)->whereRaw("c.extraction_metadata->'quality'->>'format'=?", [$format])->orderBy('c.id')->limit(2)->pluck('c.id')->all(), 'format:'.$format.':'.$lang);
    }
}
$v = json_decode(file_get_contents(dirname(__DIR__).'/docs/ai_p2/final_validation.json'), true);
$add(array_slice(array_column($v['oversize_examples'], 'document_content_id'), 0, 10), 'largest_atomic_tables');
foreach (['chapter', 'point', 'subpoint', 'paragraph', 'title', 'appendix'] as $type) {
    $add($db->table('document_chunks as k')->join('document_chunk_sets as s', 's.id', '=', 'k.chunk_set_id')->where('s.layer', 'source')->where('s.is_current', true)->where('k.section_type', $type)->whereRaw('char_length(k.content)<80')->orderBy('k.id')->limit(3)->pluck('k.document_content_id')->all(), 'short:'.$type);
}
$add($db->table('document_contents')->selectRaw('min(id) AS id')->groupBy('language', 'quality_status')->pluck('id')->all(), 'quality_balance');
if (count($reasons) > 100) {
    throw new RuntimeException('Diverse priority set exceeds 100; inspect before selecting.');
}
$add($db->table('document_contents')->whereNotIn('id', array_keys($reasons))->orderByRaw("md5(id::text || 'p3-diverse100')")->limit(100 - count($reasons))->pluck('id')->all(), 'deterministic_supplement');
$ids = array_map('intval', array_keys($reasons));
sort($ids);
$details = $source->sources()->whereIn('c.id', $ids)->select('c.id', 'c.document_id', 'c.language', 'c.quality_status', 'd.document_type')->selectRaw("c.extraction_metadata->'quality'->>'format' AS format")->orderBy('c.id')->get()->all();
file_put_contents($file, json_encode(['seed' => 'p3-diverse100', 'content_ids' => $ids, 'selection_reasons' => $reasons, 'details' => $details], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['selected' => count($ids), 'types' => array_unique(array_column($details, 'document_type')), 'formats' => array_unique(array_column($details, 'format'))], JSON_UNESCAPED_UNICODE)."\n";
