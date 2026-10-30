<?php

/** One-time diagnostic provenance correction; no canonical/content/history writes. */
use App\Models\SourceArchiveObservation;
use App\Services\Scraping\DurableCampaign;
use Illuminate\Support\Facades\DB;

require __DIR__.'/runtime.php';
if (($argv[1] ?? '') !== 'execute') {
    throw new RuntimeException('Explicit execute required.');
}
$campaign = app(DurableCampaign::class);
$state = $campaign->claim('p6-validation-20261029');
try {
    if ($state['status'] !== 'paused' || $state['used'] !== 113 || DB::table('jobs')->exists()) {
        throw new RuntimeException('Only the recorded idle controlled-pause checkpoint may be corrected.');
    }
    $before = p6Read('parser_preservation')['app/Scraping/LexUz/LexUzParser.php']['before_sha256'];
    $current = file_get_contents(app_path('Scraping/LexUz/LexUzParser.php'));
    $restored = str_replace("'английский', 'инглизча' => 'en'", "'английский' => 'en'", $current);
    if (hash('sha256', $restored) !== $before) {
        throw new RuntimeException('The only parser change must be the observed English label.');
    }
    $changed = DB::transaction(function () use ($before) {
        $changed = [];
        foreach (SourceArchiveObservation::where('context->campaign_id', 'p6-validation-20261029')->lockForUpdate()->get() as $observation) {
            $context = $observation->context;
            if ($context['parser_sha256'] !== $before) {
                $context['parser_hash_correction'] = ['at' => now()->toAtomString(), 'disk_sha256_recorded_at_finish' => $context['parser_sha256'],
                    'reason' => 'Controller1779680 loaded the original parser before the one-label edit; that process was paused/restarted. No extracted content was imported.'];
                $context['parser_sha256'] = $before;
                $observation->update(['context' => $context]);
                $changed[] = $observation->id;
            }
        }

        return $changed;
    });
    p6Save('validation_provenance_reconciliation', ['at' => now()->toAtomString(), 'executed_parser_sha256' => $before, 'changed_observation_ids' => $changed,
        'canonical_or_content_changes' => 0, 'reason' => 'Correct runtime version attribution after controlled worker restart; original source hashes, fetch times and diagnostics unchanged.']);
    p6Audit('controlled_pause_integrity');
} finally {
    $campaign->release();
}
