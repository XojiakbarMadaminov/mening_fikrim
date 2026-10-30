<?php

return [
    // Read-only machine identity. Empty means API access is disabled; never log bearer tokens.
    'token_sha256' => env('CORPUS_REPORT_TOKEN_SHA256', ''),
];
