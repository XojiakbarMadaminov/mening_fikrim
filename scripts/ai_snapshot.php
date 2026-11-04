<?php

// Standalone bootstrap: never boots the parser application or its service providers.
require dirname(__DIR__).'/vendor/autoload.php';
ini_set('zend.exception_ignore_args', '1');

use Symfony\Component\Process\Process;

$options = getopt('', ['source-env:', 'output:']);
$sourceEnv = $options['source-env'] ?? '';
$output = $options['output'] ?? '';
if (! is_file($sourceEnv) || ! str_starts_with($output, '/') || file_exists($output)) {
    throw new RuntimeException('Provide --source-env and a NEW absolute --output directory outside the repository.');
}
$parts = [];
foreach (explode('/', $output) as $part) {
    if ($part === '..') {
        array_pop($parts);
    } elseif ($part !== '' && $part !== '.') {
        $parts[] = $part;
    }
}
$output = '/'.implode('/', $parts);
$parent = dirname($output);
while (! is_dir($parent) && $parent !== '/') {
    $parent = dirname($parent);
}
$output = realpath($parent).substr($output, strlen($parent));
if (file_exists($output)) {
    throw new RuntimeException('Canonical snapshot output already exists; refusing to overwrite.');
}
$root = realpath(dirname(__DIR__));
$sourceRoot = realpath(dirname($sourceEnv));
if (str_starts_with($output, $root.'/') || str_starts_with($output, $sourceRoot.'/')) {
    throw new RuntimeException('Snapshot must be outside both Git checkouts.');
}
if (file_exists(dirname(__DIR__).'/.env')) {
    throw new RuntimeException('Refusing an existing worktree .env before snapshot or database creation.');
}
umask(0077);
$values = Dotenv\Dotenv::parse(file_get_contents($sourceEnv));
if (($values['DB_DATABASE'] ?? null) !== 'mening_fikrim_active' || ! empty($values['DB_URL'])) {
    throw new RuntimeException('Source must be the explicit main database, without DB_URL.');
}
$host = $values['DB_HOST'] ?? '127.0.0.1';
$port = $values['DB_PORT'] ?? '5432';
$user = $values['DB_USERNAME'];
$password = $values['DB_PASSWORD'];
$connect = fn ($db, $u, $p) => new PDO("pgsql:host=$host;port=$port;dbname=$db", $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
// Administrative actions connect to postgres, never the main application database.
$admin = $connect('postgres', $user, $password);
$target = 'mening_fikrim_ai_dev';
$role = 'mening_fikrim_ai_dev_owner';
if ($admin->query("SELECT 1 FROM pg_database WHERE datname = '$target'")->fetchColumn()
    || $admin->query("SELECT 1 FROM pg_roles WHERE rolname = '$role'")->fetchColumn()) {
    throw new RuntimeException('AI database or role already exists; refusing replacement or cleanup.');
}
mkdir($output, 0700, true);
$source = $connect('mening_fikrim_active', $user, $password);
$source->exec('SET default_transaction_read_only = on');
$source->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
$snapshot = $source->query('SELECT pg_export_snapshot()')->fetchColumn();
$manifest = ['started_at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tashkent')))->format(DATE_ATOM), 'snapshot' => $snapshot, 'source_database' => 'mening_fikrim_active', 'source_read_only' => $source->query('SHOW transaction_read_only')->fetchColumn(), 'tables' => []];
$tables = ['document_sources', 'documents', 'document_identifiers', 'document_contents'];
foreach ($tables as $table) {
    $hash = hash_init('sha256');
    $count = 0;
    $statement = $source->query("SELECT row_to_json(t)::text FROM (SELECT * FROM $table ORDER BY id) t");
    while ($row = $statement->fetchColumn()) {
        hash_update($hash, $row."\n");
        $count++;
    }
    $manifest['tables'][$table] = ['rows' => $count, 'sha256' => hash_final($hash)];
}
$dump = $output.'/corpus.dump';
$args = ['pg_dump', '--host='.$host, '--port='.$port, '--username='.$user, '--dbname=mening_fikrim_active', '--format=custom', '--no-owner', '--no-privileges', '--lock-wait-timeout=5s', '--snapshot='.$snapshot, '--file='.$dump];
foreach ($tables as $table) {
    $args[] = '--table=public.'.$table;
}
$process = new Process($args, null, ['PGPASSWORD' => $password, 'PGOPTIONS' => '-c default_transaction_read_only=on']);
$process->setTimeout(600);
$process->mustRun();
$source->exec('COMMIT');
$manifest['dump_sha256'] = hash_file('sha256', $dump);
$aiPassword = bin2hex(random_bytes(32));
$admin->exec('CREATE ROLE '.$role.' LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '.$admin->quote($aiPassword));
$admin->exec('CREATE DATABASE '.$target.' OWNER '.$role.' TEMPLATE template0 ENCODING \'UTF8\'');
$admin->exec('REVOKE ALL ON DATABASE '.$target.' FROM PUBLIC');
$process = new Process(['pg_restore', '--host='.$host, '--port='.$port, '--username='.$role, '--dbname='.$target, '--no-owner', '--no-privileges', '--single-transaction', '--exit-on-error', $dump], null, ['PGPASSWORD' => $aiPassword]);
$process->setTimeout(600);
$process->mustRun();
$ai = $connect($target, $role, $aiPassword);
if ($ai->query('SELECT current_database()')->fetchColumn() !== $target) {
    throw new RuntimeException('Unexpected restore target.');
}
foreach ($tables as $table) {
    $hash = hash_init('sha256');
    $count = 0;
    $statement = $ai->query("SELECT row_to_json(t)::text FROM (SELECT * FROM $table ORDER BY id) t");
    while ($row = $statement->fetchColumn()) {
        hash_update($hash, $row."\n");
        $count++;
    }
    $actual = ['rows' => $count, 'sha256' => hash_final($hash)];
    if ($actual !== $manifest['tables'][$table]) {
        throw new RuntimeException('Restore verification failed for '.$table);
    }
}
// The AI role owns only the new database. It has no SELECT/UPDATE rights on main tables.
$env = "APP_NAME=\"Mening Fikrim AI DEV\"\nAPP_ENV=local\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\nAPP_DEBUG=false\nAPP_TIMEZONE=Asia/Tashkent\nAI_DEV_ENABLED=true\nDB_CONNECTION=pgsql_ai\nDB_HOST=$host\nDB_PORT=$port\nDB_DATABASE=$target\nDB_USERNAME=$role\nDB_PASSWORD=$aiPassword\nAI_DB_CONNECTION=pgsql_ai\nAI_DB_HOST=$host\nAI_DB_PORT=$port\nAI_DB_DATABASE=$target\nAI_DB_USERNAME=$role\nAI_DB_PASSWORD=$aiPassword\nQUEUE_CONNECTION=ai_database\nQUEUE_FAILED_DRIVER=null\nCACHE_STORE=array\nSESSION_DRIVER=array\nLOG_CHANNEL=single\nSCRAPING_LIVE_ENABLED=false\nSCRAPING_SCHEDULER_ENABLED=false\nSOURCE_ARCHIVE_ENABLED=false\n";
if (file_exists(dirname(__DIR__).'/.env')) {
    throw new RuntimeException('Refusing to replace an existing worktree .env; restored database retained.');
}
file_put_contents(dirname(__DIR__).'/.env', $env);
chmod(dirname(__DIR__).'/.env', 0600);
$manifest['verified_at'] = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tashkent')))->format(DATE_ATOM);
$manifest['target_database'] = $target;
$manifest['operational_tables_copied'] = false;
file_put_contents($output.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
