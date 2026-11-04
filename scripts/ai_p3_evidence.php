<?php

// Acceptance helpers, not an application workflow. Every query uses the guarded AI connection.
function p3RowsHash($db, string $table, int $batch = 100): array
{
    if (! in_array($table, ['document_sources', 'documents', 'document_identifiers', 'document_contents', 'document_chunks', 'retrieval_passages'], true)) {
        throw new InvalidArgumentException('Not an evidence table.');
    }
    $hash = hash_init('sha256');
    $last = 0;
    $count = 0;
    do {
        $rows = $db->table($table)->where('id', '>', $last)->orderBy('id')->limit($batch)->select('id')->selectRaw('row_to_json('.$table.')::text AS row')->get();
        foreach ($rows as $row) {
            hash_update($hash, $row->row."\n");
            $last = $row->id;
            $count++;
        }
    } while ($rows->count() === $batch);

    return ['rows' => $count, 'sha256' => hash_final($hash)];
}

function p3Preservation($db, string $root): array
{
    $manifest = json_decode(file_get_contents($root.'/docs/ai/snapshot_manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $result = [];
    foreach ($manifest['tables'] as $table => $expected) {
        $actual = p3RowsHash($db, $table);
        $result[$table] = ['unchanged' => $actual === $expected, ...$actual];
    }
    $before = json_decode(file_get_contents($root.'/docs/ai_p3/before.json'), true, flags: JSON_THROW_ON_ERROR)['source_chunk_rows'];
    $actual = p3RowsHash($db, 'document_chunks', 250);
    $result['document_chunks'] = ['unchanged' => $actual['rows'] === $before['count'] && $actual['sha256'] === $before['sha256'], ...$actual];

    return $result;
}

function p3Relations($db): array
{
    return $db->select("SELECT c.relname,pg_table_size(c.oid) AS table_bytes,pg_indexes_size(c.oid) AS index_bytes FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind='r' ORDER BY pg_total_relation_size(c.oid) DESC");
}

function p3Quality($db): array
{
    return [
        'suitability' => $db->select('SELECT p.suitability,count(*) AS passages FROM retrieval_passages p JOIN document_chunk_sets r ON r.id=p.chunk_set_id WHERE r.is_current GROUP BY 1 ORDER BY 1'),
        'reasons' => $db->select("SELECT reason,count(*) AS passages FROM retrieval_passages p JOIN document_chunk_sets r ON r.id=p.chunk_set_id CROSS JOIN LATERAL jsonb_array_elements_text(p.metadata->'quality'->'reasons') reason WHERE r.is_current GROUP BY 1 ORDER BY count(*) DESC"),
        'axes' => $db->select("SELECT p.metadata->'quality'->'source_extraction'->>'status' AS source_quality,p.metadata->'quality'->>'structure' AS structure,p.metadata->'quality'->>'human_review' AS human_review,count(*) AS passages FROM retrieval_passages p JOIN document_chunk_sets r ON r.id=p.chunk_set_id WHERE r.is_current GROUP BY 1,2,3 ORDER BY count(*) DESC"),
    ];
}
