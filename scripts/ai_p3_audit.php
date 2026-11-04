<?php

use App\Services\Legal\AiDatabaseGuard;
use App\Services\Legal\ChunkDocumentService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = app(AiDatabaseGuard::class)->connection();
$service = app(ChunkDocumentService::class);
$file = dirname(__DIR__).'/docs/ai_p3/before.json';
if (is_file($file)) {
    throw new RuntimeException('P3 starting evidence already exists.');
}
$r = ['at' => now()->toIso8601String(), 'status' => $service->status(4000), 'disk_free_bytes' => disk_free_space(dirname(__DIR__))];
$r['relations'] = $db->select("SELECT c.relname, pg_table_size(c.oid) AS table_bytes,pg_indexes_size(c.oid) AS index_bytes FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r' ORDER BY pg_total_relation_size(c.oid) DESC");
$r['indexes'] = $db->select("SELECT tablename,indexname,indexdef FROM pg_indexes WHERE schemaname='public' ORDER BY tablename,indexname");
$r['review_reasons'] = $db->select("SELECT reason,count(*) AS chunks FROM document_chunks k JOIN document_chunk_sets s ON s.id=k.chunk_set_id CROSS JOIN LATERAL jsonb_array_elements_text(k.metadata->'review_reasons') reason WHERE s.is_current AND s.chunking_version='legal-v2' GROUP BY reason ORDER BY count(*) DESC");
$r['oversize_causes'] = $db->select("SELECT (k.metadata->>'contains_table')::boolean AS has_table,k.metadata->>'source_format' AS format,k.section_type,count(*) AS chunks,max(char_length(k.content)) AS max_chars FROM document_chunks k JOIN document_chunk_sets s ON s.id=k.chunk_set_id WHERE s.is_current AND char_length(k.content)>4000 GROUP BY 1,2,3 ORDER BY count(*) DESC");
$r['short_causes'] = $db->select("SELECT k.section_type,(k.metadata->>'contains_table')::boolean AS has_table,k.metadata->>'source_format' AS format,count(*) AS chunks FROM document_chunks k JOIN document_chunk_sets s ON s.id=k.chunk_set_id WHERE s.is_current AND char_length(k.content)<80 GROUP BY 1,2,3 ORDER BY count(*) DESC");
$r['source_quality_issues'] = $db->select('SELECT issue,count(*) AS contents FROM document_contents c CROSS JOIN LATERAL jsonb_array_elements_text(c.quality_issues) issue GROUP BY issue ORDER BY count(*) DESC LIMIT 40');
$hash = hash_init('sha256');
$last = 0;
$count = 0;
do {
    $rows = $db->table('document_chunks')->where('id', '>', $last)->orderBy('id')->limit(250)->select('id')->selectRaw('row_to_json(document_chunks)::text AS row')->get();
    foreach ($rows as $row) {
        hash_update($hash, $row->row."\n");
        $last = $row->id;
        $count++;
    }
} while ($rows->count() === 250);
$r['source_chunk_rows'] = ['count' => $count, 'sha256' => hash_final($hash)];
$r['projected_additional_bytes'] = 4 * 651169442 + 549454 * 600;
$r['projection_note'] = 'Conservative body/context/lineage/index allowance; actual pilot relation growth must be checked before full processing. No v2 chunk duplication is needed.';
file_put_contents($file, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['at' => $r['at'], 'status' => $r['status'], 'review_reasons' => $r['review_reasons'], 'oversize_causes' => $r['oversize_causes'], 'short_causes' => array_slice($r['short_causes'], 0, 8), 'source_chunk_rows' => $r['source_chunk_rows'], 'disk_free_GiB' => $r['disk_free_bytes'] / 1024 ** 3, 'projected_GiB' => $r['projected_additional_bytes'] / 1024 ** 3], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n";
