<?php

return [
    // Enabling storage does not establish permission to retain third-party source bytes.
    'enabled' => (bool) env('SOURCE_ARCHIVE_ENABLED', false),
    'retention_confirmed' => (bool) env('SOURCE_ARCHIVE_RETENTION_CONFIRMED', false),
    'disk' => env('SOURCE_ARCHIVE_DISK', 'legal_sources'),
    'max_file_bytes' => (int) env('SOURCE_ARCHIVE_MAX_FILE_BYTES', 32 * 1024 * 1024),
    'max_capture_bytes' => (int) env('SOURCE_ARCHIVE_MAX_CAPTURE_BYTES', 256 * 1024 * 1024),
    'quota_bytes' => (int) env('SOURCE_ARCHIVE_QUOTA_BYTES', 2 * 1024 * 1024 * 1024),
    'quota_failure_policy' => 'stop_campaign',
    'max_reprocess_limit' => 30,
];
