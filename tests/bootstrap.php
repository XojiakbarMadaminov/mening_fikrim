<?php

require dirname(__DIR__).'/vendor/autoload.php';

// Refuse destructive test migrations unless a separate, explicit PostgreSQL test DB is configured.
if (! is_file(dirname(__DIR__).'/.env.testing')) {
    throw new RuntimeException('Copy .env.testing.example to .env.testing and configure a dedicated test database.');
}
Dotenv\Dotenv::createImmutable(dirname(__DIR__), '.env.testing')->safeLoad();
$database = $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE');
if (! is_string($database) || $database !== 'mening_fikrim_active_test' || ! empty($_ENV['DB_URL']) || getenv('DB_URL')) {
    throw new RuntimeException('Tests require an explicit database named mening_fikrim_active_test and no DB_URL.');
}
