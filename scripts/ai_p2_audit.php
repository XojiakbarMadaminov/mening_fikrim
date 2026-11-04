<?php

use App\Services\Legal\AiDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
if (is_file(dirname(__DIR__).'/docs/ai_p2/before.json')) {
    throw new RuntimeException('The P2 starting evidence already exists; refusing to overwrite it.');
}
$db->beginTransaction();
$db->statement('SET TRANSACTION READ ONLY');
$r = ['at' => now()->toIso8601String(), 'database' => $db->getDatabaseName()];
$r['counts'] = $db->selectOne('SELECT (SELECT count(*) FROM documents) AS documents, (SELECT count(*) FROM document_contents) AS contents, (SELECT count(*) FROM document_chunk_sets) AS sets, (SELECT count(*) FROM document_chunks) AS chunks, (SELECT count(*) FROM ai_jobs) AS queued');
$r['source_quality'] = $db->select("SELECT language, quality_status, extraction_metadata->'quality'->>'format' AS format, count(*) FROM document_contents GROUP BY 1,2,3 ORDER BY 1,2,3");
$r['categories'] = $db->select('SELECT document_type,count(*) FROM documents GROUP BY 1 ORDER BY count(*) DESC');
$r['lengths'] = $db->selectOne('SELECT min(char_length(content)) AS min, max(char_length(content)) AS max, avg(char_length(content)) AS average, sum(octet_length(content)) AS bytes FROM document_contents');
$r['largest'] = $db->select('SELECT c.id,c.document_id,c.language,c.quality_status,char_length(c.content) AS chars,d.document_type,left(d.title,150) AS title FROM document_contents c JOIN documents d ON d.id=c.document_id ORDER BY octet_length(c.content) DESC LIMIT 10');
$r['chunk_quality'] = $db->select('SELECT quality_status,count(*) FROM document_chunks GROUP BY 1');
$r['chunk_lengths'] = $db->selectOne('SELECT min(char_length(content)) AS min,max(char_length(content)) AS max,avg(char_length(content)) AS average,count(*) FILTER (WHERE char_length(content)>4000) AS over_target FROM document_chunks');
foreach (['document_chunk_sets', 'document_chunks'] as $table) {
    $hash = hash_init('sha256');
    foreach ($db->table($table)->orderBy('id')->cursor() as $row) {
        hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR)."\n");
    }
    $r[$table.'_sha256'] = hash_final($hash);
}
$db->rollBack();
file_put_contents(dirname(__DIR__).'/docs/ai_p2/before.json', json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
