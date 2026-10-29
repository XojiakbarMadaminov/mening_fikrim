<?php

/** Read-only P2 evidence audit. Credentials are loaded locally and never serialized. */
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('select current_database() as name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Audit must start from the fixed development database.');
}
$baseline = json_decode(file_get_contents(dirname(__DIR__).'/p2/database_before.json'), true, flags: JSON_THROW_ON_ERROR);
$config = DB::connection()->getConfig();
$databases = DB::select('select datname from pg_database where not datistemplate and datallowconn and datname like ? order by datname', ['mening_fikrim%']);
$after = ['databases' => array_column($databases, 'datname'), 'existing' => [], 'unrelated_table_counts_unchanged' => true];
foreach ($databases as $database) {
    $name = $database->datname;
    $pdo = new PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$name, $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->beginTransaction();
    $pdo->exec('SET TRANSACTION READ ONLY');
    $tables = $pdo->query("select tablename from pg_tables where schemaname='public' order by tablename")->fetchAll(PDO::FETCH_COLUMN);
    $rows = [];
    foreach ($tables as $table) {
        $rows[$table] = (int) $pdo->query('select count(*) from "'.str_replace('"', '""', $table).'"')->fetchColumn();
    }
    $after['existing'][$name] = ['tables' => $tables, 'rows' => $rows];
    if (! in_array($name, ['mening_fikrim_active', 'mening_fikrim_active_test'], true)) {
        $equal = $after['existing'][$name] === ($baseline['existing'][$name] ?? null);
        $after['unrelated_comparison'][$name] = $equal;
        $after['unrelated_table_counts_unchanged'] = $after['unrelated_table_counts_unchanged'] && $equal;
    }
    $pdo->rollBack();
}
$after['development_statuses'] = DB::table('documents')->selectRaw('status, count(*) as count')->groupBy('status')->get()->toArray();
$after['development_languages'] = DB::table('document_contents')->selectRaw('language, count(*) as count')->groupBy('language')->orderBy('language')->get()->toArray();
$after['markdown'] = [];
foreach (DB::table('document_contents')->orderBy('id')->get(['id', 'document_id', 'language', 'content', 'content_hash']) as $content) {
    preg_match_all('/^#{1,3} .+$/mu', $content->content, $headings);
    preg_match_all('/^#{1,3}\s*$/mu', $content->content, $emptyHeadings);
    $after['markdown'][] = ['id' => $content->id, 'document_id' => $content->document_id, 'language' => $content->language,
        'characters' => mb_strlen($content->content), 'heading_count' => count($headings[0]), 'empty_heading_count' => count($emptyHeadings[0]),
        'hash_matches' => hash_equals($content->content_hash, hash('sha256', $content->content)), 'prefix' => mb_substr($content->content, 0, 120)];
}
$after['versions'] = ['laravel' => app()->version(), 'php' => PHP_VERSION, 'postgresql' => DB::selectOne('show server_version')->server_version];
$after['configuration'] = ['live_enabled' => config('scraping.live_enabled'), 'scheduler_enabled' => config('scraping.scheduler_enabled'),
    'job_timeout' => config('scraping.job_timeout'), 'retry_after' => config('queue.connections.database.retry_after')];
file_put_contents(__DIR__.'/database_after.json', json_encode($after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
echo json_encode(['databases' => $after['databases'], 'unrelated_table_counts_unchanged' => $after['unrelated_table_counts_unchanged'],
    'development_rows' => $after['existing']['mening_fikrim_active']['rows'], 'testing_rows' => $after['existing']['mening_fikrim_active_test']['rows']], JSON_PRETTY_PRINT)."\n";
