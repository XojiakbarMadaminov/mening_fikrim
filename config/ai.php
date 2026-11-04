<?php

return [
    'enabled' => (bool) env('AI_DEV_ENABLED', false),
    'connection' => env('AI_DB_CONNECTION', 'pgsql_ai'),
    'version' => 'legal-v2',
    'chunk_size' => (int) env('AI_CHUNK_SIZE', 4000),
    'max_chunk_size' => (int) env('AI_MAX_CHUNK_SIZE', 8000),
    'structure_splitting' => true,
    'overlap_chars' => 0,
    'max_attempts' => 3,
    'batch_size' => 10,
    'retrieval_enabled' => (bool) env('AI_RETRIEVAL_ENABLED', true),
    'retrieval_version' => 'legal-v3',
    'passage_target_chars' => 3000,
    'passage_max_chars' => 3500,
    'context_max_chars' => 512,
    'embedding_max_chars' => 4096,
    'short_merge_chars' => 160,
    'queue' => 'legal-chunking',
];
