<?php

use App\Services\Reporting\CampaignReportingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (DB::selectOne('SELECT current_database() AS name')->name !== 'mening_fikrim_active') {
    throw new RuntimeException('Fixed development database required.');
}
$reports = app(CampaignReportingService::class);
$campaign = $reports->campaign('p6-1-completion-150-20261029');
$xml = simplexml_load_file(__DIR__.'/full_junit.xml');
$suite = $xml->xpath('/testsuites/testsuite')[0];
$result = ['observed_at' => now()->toAtomString(), 'implementation_ready' => true, 'live_started' => false, 'campaign' => $campaign,
    'corpus' => $reports->corpus(), 'p6' => $reports->campaign('p6-corpus-1000-20261029'),
    'tests' => ['tests' => (int) $suite['tests'], 'assertions' => (int) $suite['assertions'], 'failures' => (int) $suite['failures'], 'errors' => (int) $suite['errors']],
    'pint' => json_decode(file_get_contents(__DIR__.'/pint.txt'), true), 'preservation' => json_decode(file_get_contents(__DIR__.'/preservation.json'), true),
    'recommendation' => 'READY_FOR_EXPLICIT_OPERATOR_START; live150 acceptance pending'];
if (! $campaign['prepared'] || $campaign['status'] !== 'paused' || $campaign['new_canonical'] !== 0 || $campaign['http']['used'] !== 0
    || $result['preservation']['errors'] !== [] || $result['p6']['new_canonical'] !== 850 || $result['p6']['status'] !== 'stopped'
    || $result['tests']['errors'] + $result['tests']['failures'] !== 0 || ($result['pint']['result'] ?? null) !== 'passed') {
    throw new RuntimeException('Prepared handoff gate failed.');
}
file_put_contents(__DIR__.'/final_summary.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
echo "P6.1 READY: prepared paused,0/150NEW,0/3500HTTP; no live started\n";
echo 'Tests '.$result['tests']['tests'].' / assertions '.$result['tests']['assertions']." PASS; Pint PASS; preservation PASS\n";
echo 'Corpus '.$result['corpus']['canonical_documents'].'documents / '.$result['corpus']['contents']."contents\n";
