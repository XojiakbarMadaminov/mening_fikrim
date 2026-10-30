<?php

namespace App\Services\Reporting;

use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\ScrapeCampaign;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunItem;
use App\Services\Scraping\DurableCampaign;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Curated read-only snapshots. No SQL/file/shell input or campaign control is accepted. */
class CampaignReportingService
{
    private function safeStopReason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        return preg_match('/^[a-z0-9_-]{1,100}$/D', $reason) ? $reason : explode(':', $reason, 2)[0];
    }

    private function snapshot(callable $read): array
    {
        return DB::transaction(function () use ($read) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return ['observed_at' => now()->toAtomString(), ...$read()];
        });
    }

    public function campaigns(): array
    {
        return $this->snapshot(fn () => ['campaigns' => ScrapeCampaign::orderByDesc('id')->get(['campaign_id', 'campaign_type', 'status', 'target_new_canonical', 'current_new_canonical_count', 'used_http_requests', 'max_http_requests', 'stop_reason'])->map(function ($campaign) {
            $data = $campaign->toArray();
            $data['stop_reason'] = $this->safeStopReason($campaign->stop_reason);

            return $data;
        })->all(),
            'note' => 'Stored progress counters may lag an in-flight batch; campaign report derives current NEW canonicals from durable created-item receipts.']);
    }

    public function finalReport(string $id): array
    {
        return $this->snapshot(function () use ($id) {
            $campaign = ScrapeCampaign::where('campaign_id', $id)->firstOrFail();
            $checkpoint = $campaign->checkpoints()->where('name', 'final-report')->first();

            return ['campaign_id' => $id, 'snapshot_type' => 'stored_final', 'available' => $checkpoint !== null,
                'report' => $checkpoint?->state['report'] ?? null,
                'note' => $checkpoint === null ? 'No stored final report; prepared/running campaigns are not claimed complete.' : 'Immutable terminal snapshot; its observation time is inside report.'];
        });
    }

    private function grouped($query, string $column): array
    {
        return $query->selectRaw($column.',count(*) AS n')->groupByRaw($column)->pluck('n', $column)->map(fn ($n) => (int) $n)->all();
    }

    private function languageSummary($documents): array
    {
        $summary = ['available' => 0, 'not_available' => 0, 'unknown' => 0, 'available_but_failed_or_missing' => 0, 'unassessed_documents' => 0, 'coverage' => []];
        foreach ($documents as $document) {
            $map = $document->metadata['language_availability'] ?? [];
            $summary['unassessed_documents'] += (int) ($map === []);
            foreach ($map as $language => $entry) {
                if (isset($summary[$entry['status'] ?? ''])) {
                    $summary[$entry['status']]++;
                }
                if (($entry['status'] ?? null) === 'available' && (! $document->contents->contains('language', $language)
                    || ($document->metadata['validation']['languages'][$language]['quality_status'] ?? null) === 'extraction_failed')) {
                    $summary['available_but_failed_or_missing']++;
                }
            }
            $coverage = $document->language_coverage_status;
            $summary['coverage'][$coverage] = ($summary['coverage'][$coverage] ?? 0) + 1;
        }

        return $summary;
    }

    private function documents($ids = null)
    {
        return Document::when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->select('id', 'metadata')->with(['contents' => fn ($q) => $q->select('id', 'document_id', 'language', 'quality_status')
            ->selectRaw("CASE WHEN pending_candidate IS NULL THEN NULL ELSE '{}'::jsonb END AS pending_candidate")])->lazyById(100);
    }

    public function corpus(): array
    {
        return $this->snapshot(function () {
            $since = now()->subHours(24);
            $documents = Document::where('created_at', '>=', $since)->count();
            $contents = DocumentContent::where('created_at', '>=', $since)->count();

            return ['canonical_documents' => Document::count(), 'identifiers' => DB::table('document_identifiers')->count(), 'contents' => DocumentContent::count(),
                'content_quality' => $this->grouped(DocumentContent::query(), 'quality_status'), 'content_languages' => $this->grouped(DocumentContent::query(), 'language'),
                'legal_status' => $this->grouped(Document::query(), 'status'), 'language_availability' => $this->languageSummary($this->documents()),
                'today' => ['timezone' => 'Asia/Tashkent', 'new_canonical_documents' => Document::where('created_at', '>=', now('Asia/Tashkent')->startOfDay())->count()],
                'last_24_hours' => ['since' => $since->toAtomString(), 'new_canonical_documents' => $documents, 'new_contents' => $contents, 'new_documents_per_wall_hour' => $documents / 24],
                'archive' => ['retention_enabled' => (bool) config('source_archive.enabled'), 'retention_confirmed' => (bool) config('source_archive.retention_confirmed'),
                    'real_blobs' => DB::table('source_archive_blobs')->count(), 'bytes' => (int) DB::table('source_archive_blobs')->sum('file_size'),
                    'offline_fixture_test_status' => 'unavailable_at_runtime; see versioned test evidence'],
                'global_failed_jobs' => DB::table('failed_jobs')->count(),
                'limitations' => ['verified means automated checks, not human legal certification', 'unknown availability is not absence', 'finite discovery is not global Lex.uz coverage']];
        });
    }

    public function campaign(string $id): array
    {
        return $this->snapshot(function () use ($id) {
            $campaign = ScrapeCampaign::where('campaign_id', $id)->firstOrFail();
            $runIds = ScrapeRun::where('checkpoint->campaign_id', $id)->pluck('id');
            $items = ScrapeRunItem::whereIn('scrape_run_id', $runIds)->select('id', 'source_external_id', 'document_id', 'status', 'action', 'updated_at')
                ->selectRaw("jsonb_build_object('import_outcome', outcome->'import_outcome') AS outcome")->get();
            $createdIds = $items->where('action', 'created')->pluck('document_id')->filter(fn ($v) => $v !== null && $v > $campaign->baseline_max_id)->unique();
            $documentIds = $items->pluck('document_id')->filter()->unique();
            $contentQuery = DocumentContent::whereIn('document_id', $createdIds);
            $end = $campaign->completed_at ?? $campaign->stopped_at ?? ($campaign->status === 'paused' ? $campaign->paused_at : now());
            $elapsed = ($campaign->state['prepared'] ?? false) ? null : abs($campaign->started_at->diffInSeconds($end ?? now()));
            $checkpoints = [];
            foreach ($campaign->checkpoints()->lazyById(1) as $checkpoint) {
                $roots = array_filter($checkpoint->state['nodes'] ?? [], fn ($node) => ($node['depth'] ?? null) === 0);
                foreach ($roots as $node) {
                    $checkpoints[] = ['name' => $checkpoint->name, 'query' => $node['query'], 'listing_total' => $node['total'] ?? null,
                        'unique_source_ids' => count($node['rows'] ?? []), 'complete' => $node['complete'] ?? false,
                        'reason' => $node['reason'] ?? null, 'version' => $checkpoint->version, 'updated_at' => $checkpoint->updated_at->toAtomString()];
                }
            }
            $formats = [];
            foreach ((clone $contentQuery)->select('id')->selectRaw("jsonb_build_object('quality', jsonb_build_object('format', extraction_metadata->'quality'->'format')) AS extraction_metadata")->lazyById(100) as $content) {
                $format = $content->extraction_metadata['quality']['format'] ?? 'unknown';
                $formats[$format] = ($formats[$format] ?? 0) + 1;
            }
            $failedJobs = 0;
            foreach (DB::table('failed_jobs')->select('payload')->cursor() as $failure) {
                $payload = json_decode($failure->payload, true);
                if (preg_match('/^App\\\\Jobs\\\\(?:ImportDocumentJob|DiscoverDocumentsJob)#(\d+)$/D', $payload['displayName'] ?? '', $match) && $runIds->contains((int) $match[1])) {
                    $failedJobs++;
                }
            }

            return ['campaign_id' => $id, 'status' => $campaign->status, 'stop_reason' => $this->safeStopReason($campaign->stop_reason),
                'prepared' => $campaign->state['prepared'] ?? false, 'target_new_canonical' => $campaign->target_new_canonical,
                'new_canonical' => $createdIds->count(), 'baseline_canonical' => $campaign->baseline_canonical_count,
                'current_total_canonical' => Document::count(), 'source_selections' => $campaign->selected_source_count,
                'http' => ['used' => $campaign->used_http_requests, 'maximum' => $campaign->max_http_requests, 'remaining' => $campaign->max_http_requests - $campaign->used_http_requests,
                    'status_counts' => $campaign->state['http_status_counts'] ?? [], 'per_context_maximum' => $campaign->configuration_snapshot['per_context_request_limit']],
                'stage_target' => $campaign->stage_target, 'stages' => $campaign->state['stages'],
                'import_outcomes' => $items->countBy('status')->all(), 'associated_existing_canonical' => $documentIds->diff($createdIds)->count(),
                'new_canonical_content_representations' => (clone $contentQuery)->count(), 'cohort_content_quality' => $this->grouped(clone $contentQuery, 'quality_status'),
                'cohort_content_languages' => $this->grouped(clone $contentQuery, 'language'), 'cohort_formats' => $formats,
                'cohort_note' => 'Current stored representations on canonicals created by this campaign, not a historical content-update event count.',
                'language_availability' => $this->languageSummary($this->documents($documentIds)),
                'technical_failed_items' => $items->where('status', 'failed')->count(), 'associated_failed_jobs' => $failedJobs,
                'global_failed_jobs' => DB::table('failed_jobs')->count(),
                'no_usable_body_import_outcomes' => $items->filter(fn ($item) => ($item->outcome['import_outcome'] ?? null) === 'extraction_failed')->count(),
                'guard_review_items' => $items->filter(fn ($item) => $item->status === 'review' && $item->document_id === null)->count(),
                'pending_items' => $items->filter(fn ($item) => ! $item->terminal())->count(), 'pending_scraping_jobs_global' => DB::table('jobs')->where('queue', 'scraping')->count(),
                'discovery' => ['scope' => $campaign->configuration_snapshot['scope'] ?? null, 'scope_metric_status' => isset($campaign->configuration_snapshot['scope']) ? 'durable_configuration' : 'unavailable_in_legacy_configuration; see historical scope evidence',
                    'partitions' => $checkpoints, 'coverage_gaps' => array_values($campaign->state['control']['coverage_gaps'] ?? []), 'global_coverage_verified' => false],
                'recovery' => ['owner_generation' => $campaign->owner_generation, 'owner_registered' => $campaign->owner_token !== null,
                    'owner_session_live' => app(DurableCampaign::class)->hasLiveOwner($id),
                    'phase' => $campaign->state['control']['phase'] ?? 'unavailable_for_legacy_controller', 'checkpoint_count' => $campaign->checkpoints()->count(),
                    'terminal_resume_allowed' => false],
                'timing' => ['started_at' => $campaign->started_at->toAtomString(), 'elapsed_window_seconds' => $elapsed,
                    'new_per_elapsed_hour' => $elapsed ? $createdIds->count() * 3600 / $elapsed : null,
                    'active_processing_seconds' => null, 'active_metric_status' => 'unavailable; elapsed window includes pauses and worker waiting'],
                'recent_activity' => $items->sortByDesc('updated_at')->take(10)->map(fn ($item) => ['item_id' => $item->id, 'source_id' => $item->source_external_id, 'document_id' => $item->document_id, 'status' => $item->status, 'at' => $item->updated_at->toAtomString()])->values()->all(),
                'metrics_note' => 'Source IDs, aliases, language representations and canonicals are distinct. Review is not technical failure. Metadata-only no-body import diagnostics do not imply a confirmed available translation failed.'];
        });
    }

    public function quality(): array
    {
        return $this->snapshot(fn () => ['content_quality' => $this->grouped(DocumentContent::query(), 'quality_status'),
            'by_language_and_quality' => DB::table('document_contents')->selectRaw('language,quality_status,count(*) AS count')->groupBy('language', 'quality_status')->get()->toArray(),
            'review_reason_counts' => DB::select('SELECT issue,count(*)::integer AS count FROM document_contents CROSS JOIN LATERAL jsonb_array_elements_text(quality_issues) issue GROUP BY issue ORDER BY count(*) DESC'),
            'note' => 'Automated verified is not human legal accuracy certification.']);
    }

    public function failures(?string $id = null, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 30) {
            throw new InvalidArgumentException('Diagnostic limit must be1..30.');
        }

        return $this->snapshot(function () use ($id, $limit) {
            if ($id !== null) {
                ScrapeCampaign::where('campaign_id', $id)->firstOrFail();
            }
            $query = ScrapeRunItem::whereIn('status', ['failed', 'review'])->when($id !== null, fn ($q) => $q->whereHas('run', fn ($r) => $r->where('checkpoint->campaign_id', $id)));
            $count = (clone $query)->count();

            return ['campaign_id' => $id, 'total_review_or_failed_items' => $count, 'limit' => $limit, 'items' => $query->orderByDesc('id')->limit($limit)->get()->map(function ($item) {
                $error = $item->last_error ?? '';
                $category = $item->status === 'failed' ? 'technical_failure' : (($item->outcome['import_outcome'] ?? null) === 'extraction_failed' ? 'no_usable_body' : ($item->document_id !== null ? 'persisted_quality_review' : 'source_identity_or_metadata_review'));
                $issues = [];
                foreach ($item->language_results ?? [] as $language => $outcome) {
                    foreach ($outcome['quality_issues'] ?? [] as $code) {
                        if (is_string($code) && preg_match('/^[a-z0-9_:-]{1,100}$/D', $code)) {
                            $issues[] = $code;
                        }
                    }
                }

                return ['item_id' => $item->id, 'run_id' => $item->scrape_run_id, 'source_id' => $item->source_external_id, 'document_id' => $item->document_id,
                    'category' => $category, 'quality_issues' => array_values(array_unique($issues)), 'identity_conflict' => str_contains($error, 'Conflicting component requisites'), 'at' => $item->updated_at->toAtomString()];
            })->all(), 'note' => 'Bounded safe diagnostics; raw exception, credentials, queue payloads and SQL are never exposed.'];
        });
    }

    public function discovery(?string $id = null): array
    {
        $id ??= ScrapeCampaign::orderByDesc('id')->value('campaign_id');

        return $id === null ? ['observed_at' => now()->toAtomString(), 'campaign_id' => null, 'status' => 'unavailable'] : array_intersect_key($this->campaign($id), array_flip(['observed_at', 'campaign_id', 'discovery']));
    }

    public function compare(array $ids): array
    {
        if (count($ids) < 2 || count($ids) > 5 || collect($ids)->contains(fn ($id) => ! is_string($id) || ! preg_match('/^[a-z0-9-]{1,60}$/D', $id)) || count(array_unique($ids)) !== count($ids)) {
            throw new InvalidArgumentException('Compare2..5unique campaign IDs.');
        }

        // Separate report snapshots carry their observation times; no false atomic comparison claim.
        return ['observed_at' => now()->toAtomString(), 'campaigns' => array_map(fn ($id) => $this->campaign($id), $ids), 'snapshot_note' => 'Each campaign has its own read-only snapshot timestamp.'];
    }

    public function markdown(array $report): string
    {
        return "# Corpus report\n\nObserved: ".($report['observed_at'] ?? 'unavailable')."\n\n```json\n".json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n```\n";
    }
}
