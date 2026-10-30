<?php

namespace App\Services\Scraping;

use App\Models\ScrapeRun;
use App\Models\ScrapeRunItem;
use Illuminate\Support\Facades\DB;

class CampaignIntegrity
{
    public function baseline(): array
    {
        return ['documents' => DB::table('documents')->orderBy('id')->get(['id', 'source_id', 'external_id'])->keyBy('id')->toArray(),
            'identifiers' => DB::table('document_identifiers')->orderBy('id')->get(['id', 'source_id', 'document_id', 'external_id'])->keyBy('id')->toArray(),
            'contents' => DB::table('document_contents')->orderBy('id')->get(['id', 'document_id', 'language', 'content_hash', 'quality_status'])->keyBy('id')->toArray(),
            'failed_job_ids' => DB::table('failed_jobs')->orderBy('id')->pluck('id')->all()];
    }

    public function audit(array $baseline, string $id): array
    {
        $errors = [];
        $current = $this->baseline();
        foreach (['documents', 'identifiers', 'contents'] as $table) {
            foreach ($baseline[$table] as $key => $old) {
                $old = (array) $old;
                $row = isset($current[$table][$key]) ? (array) $current[$table][$key] : null;
                if ($row === null || array_diff_assoc($old, $row) !== []) {
                    $errors[] = 'preservation:'.$table.':'.$key;
                }
            }
        }
        if ($baseline['failed_job_ids'] !== $current['failed_job_ids']) {
            $errors[] = 'failed_jobs_changed';
        }
        foreach (DB::table('document_contents')->select('id', 'content', 'content_hash')->lazyById(10) as $content) {
            if (trim($content->content) === '' || hash('sha256', $content->content) !== $content->content_hash) {
                $errors[] = 'invalid_content:'.$content->id;
            }
        }
        foreach (['document_contents' => 'document_id,language', 'documents' => 'source_id,external_id', 'document_identifiers' => 'source_id,external_id'] as $table => $columns) {
            if (DB::table($table)->selectRaw($columns)->groupByRaw($columns)->havingRaw('count(*)>1')->exists()) {
                $errors[] = 'duplicate:'.$table;
            }
        }
        foreach (['document_contents', 'document_identifiers'] as $table) {
            if (DB::table($table.' AS child')->leftJoin('documents AS d', 'd.id', '=', 'child.document_id')->whereNull('d.id')->exists()) {
                $errors[] = 'orphan:'.$table;
            }
        }
        $runIds = ScrapeRun::where('checkpoint->campaign_id', $id)->pluck('id');
        $items = ScrapeRunItem::whereIn('scrape_run_id', $runIds);
        if ((clone $items)->where('status', 'failed')->exists()) {
            $errors[] = 'technical_failed_item';
        }
        if ((clone $items)->whereNotNull('document_id')->whereRaw("(outcome->'identity_verified') IS DISTINCT FROM 'true'::jsonb")->exists()) {
            $errors[] = 'unverified_persisted_identity';
        }
        $archive = app(SourceArchive::class)->audit();
        if (! $archive['ok']) {
            $errors[] = 'archive_integrity';
        }

        return ['observed_at' => now()->toAtomString(), 'errors' => $errors, 'archive' => $archive, 'pending_jobs' => DB::table('jobs')->where('queue', 'scraping')->count()];
    }
}
