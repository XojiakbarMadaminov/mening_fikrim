<?php

use Symfony\Component\Process\Process;

// Operator-authorized read-only observation; never boot or control the MAIN application.
require dirname(__DIR__).'/vendor/autoload.php';
ini_set('zend.exception_ignore_args', '1');
$name = $argv[1] ?? 'parser_start';
if (! in_array($name, ['parser_start', 'parser_end'], true)) {
    throw new RuntimeException('Expected parser_start or parser_end.');
}
$root = '/home/xojiakbar/PhpstormProjects/mening_fikrim';
$values = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
if (($values['DB_DATABASE'] ?? '') !== 'mening_fikrim_active' || ! empty($values['DB_URL'])) {
    throw new RuntimeException('Unexpected source configuration.');
}
$db = new PDO('pgsql:host='.$values['DB_HOST'].';port='.$values['DB_PORT'].';dbname=mening_fikrim_active', $values['DB_USERNAME'], $values['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
$report = ['at' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tashkent')))->format(DATE_ATOM), 'read_only' => $db->query('SHOW transaction_read_only')->fetchColumn()];
$report['campaign'] = $db->query("SELECT campaign_id,status,current_new_canonical_count,used_http_requests,stop_reason FROM scrape_campaigns WHERE campaign_id='corpus-30000-20261030'")->fetch(PDO::FETCH_ASSOC);
$report['counts'] = $db->query('SELECT (SELECT count(*) FROM documents) AS documents,(SELECT count(*) FROM document_contents) AS contents,(SELECT count(*) FROM failed_jobs) AS failed_jobs,(SELECT count(*) FROM migrations) AS migrations')->fetch(PDO::FETCH_ASSOC);
$db->exec('COMMIT');
$report['actors'] = [];
foreach (glob('/proc/[0-9]*/cmdline') as $path) {
    $command = @file_get_contents($path);
    if ($command === false || ! preg_match('/artisan\x00documents:campaign\x00(?:worker|run)\x00--id=corpus-30000-20261030/', $command)) {
        continue;
    }
    $pid = (int) basename(dirname($path));
    $stat = explode(' ', file_get_contents(dirname($path).'/stat'));
    $report['actors'][] = ['pid' => $pid, 'command' => str_replace("\0", ' ', trim($command, "\0")), 'start_ticks' => $stat[21], 'cpu_ticks' => (int) $stat[13] + (int) $stat[14]];
}
$process = new Process(['git', '-C', $root, 'status', '--porcelain']);
$process->mustRun();
$report['original_git_status'] = $process->getOutput();
file_put_contents(dirname(__DIR__).'/docs/ai_p3/'.$name.'.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['at' => $report['at'], 'campaign' => $report['campaign'], 'actor_pids' => array_column($report['actors'], 'pid')])."\n";
