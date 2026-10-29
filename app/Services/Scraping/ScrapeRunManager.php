<?php

namespace App\Services\Scraping;

use App\Enums\DocumentStatus;
use App\Jobs\DiscoverDocumentsJob;
use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\ScrapeRun;
use App\Models\ScrapeRunItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;

class ScrapeRunManager
{
    public function __construct(private RunItemTracker $tracker, private DiscoveryPlanner $planner) {}

    public function start(DocumentSource $source, string $mode, int $limit, array $sample = [], ?string $section = null, ?string $from = null, ?string $to = null): ScrapeRun
    {
        if ($source->slug !== 'lex' || ! $source->is_active || ! in_array($mode, ['full', 'daily', 'recheck'], true) || $limit < 1 || $limit > 30) {
            throw new InvalidArgumentException('P3 permits only an active lex source and an explicit limit of 1..30.');
        }
        if (count($sample) > $limit || count(array_unique($sample)) !== count($sample)) {
            throw new InvalidArgumentException('Explicit sample must be unique and fit the run limit.');
        }
        foreach ($sample as $id) {
            if (! is_string($id) || ! preg_match('/^-?\d+$/D', $id)) {
                throw new InvalidArgumentException('Sample source IDs must be opaque signed numeric strings.');
            }
        }
        if ($section !== null && (! in_array($section, DiscoveryPlanner::ROOTS, true) || $sample !== [] || $mode === 'recheck')) {
            throw new InvalidArgumentException('A discovery section must be a verified root and cannot be combined with sample/recheck selection.');
        }
        if (($from === null) !== ($to === null) || ($from !== null && ($sample !== [] || $mode !== 'full'))) {
            throw new InvalidArgumentException('Both discovery dates are required, for bounded full discovery only.');
        }
        if ($from !== null && $this->planner->date($from) > $this->planner->date($to)) {
            throw new InvalidArgumentException('Discovery dates are reversed.');
        }
        $segments = [];
        foreach ($section === null ? DiscoveryPlanner::ROOTS : [$section] as $root) {
            $segment = ['section' => $root, 'status' => 'Y'];
            if ($mode === 'daily') {
                $segment += ['from' => now()->subDays(7)->format('d.m.Y'), 'to' => now()->format('d.m.Y')];
            } elseif ($from !== null) {
                $segment += ['from' => $from, 'to' => $to];
            }
            $segments[] = $segment;
        }

        $campaign = app(PilotCampaign::class)->admitRun($limit, $sample);

        return DB::transaction(function () use ($source, $mode, $limit, $sample, $segments, $campaign) {
            $run = ScrapeRun::create(['source_id' => $source->id, 'mode' => $mode, 'started_at' => now(), 'checkpoint' => [
                'version' => 3, 'scope' => 'bounded_sample', 'limit' => $limit, 'sample_ids' => $sample,
                'campaign_id' => $campaign,
                'segments' => $segments, 'segment_index' => 0, 'segment_results' => [], 'partition_parents' => [], 'segments_completed' => 0,
                'coverage_verified' => false, 'discovery_finished' => false, 'jobs_reconciled' => false,
                'languages_complete' => false, 'quality_passed' => false,
            ]]);
            $this->push(new DiscoverDocumentsJob($run->id));

            return $run;
        });
    }

    /** Database queue insertion and checkpoint mutation use the SAME PostgreSQL transaction. */
    public function push(object $job): int|string
    {
        if (config('queue.connections.database.connection') !== null && config('queue.connections.database.connection') !== config('database.default')) {
            throw new InvalidArgumentException('Atomic checkpoints require the database queue on the application database connection.');
        }

        return Queue::connection('database')->push($job->beforeCommit(), '', 'scraping');
    }

    public function selectPage(int $runId, array $page): bool
    {
        return DB::transaction(function () use ($runId, $page) {
            $run = ScrapeRun::lockForUpdate()->findOrFail($runId);
            $cp = $run->checkpoint;
            $key = (string) $cp['segment_index'];
            $segment = $cp['segments'][$cp['segment_index']] ?? [];
            $old = $cp['segment_results'][$key] ?? [];
            if (isset($old['total']) && $old['total'] !== $page['total']) {
                throw new RuntimeException('Source total changed during checkpoint replay.');
            }
            if ($page['page'] < ($old['committed_page'] ?? 0)) {
                return $run->items()->count() < $cp['limit'];
            }
            if ($page['page'] === ($old['committed_page'] ?? 0) && isset($old['prefix_fingerprint'], $page['prefix_fingerprint']) && $old['prefix_fingerprint'] !== $page['prefix_fingerprint']) {
                throw new RuntimeException('Source listing prefix changed during checkpoint replay.');
            }
            if ($page['page'] === 1 && isset($segment['from'], $segment['to']) && $page['total'] > config('scraping.partition_target')) {
                $cp['partition_pending'] = ['segment' => $segment, 'total' => $page['total']];
                $run->update(['checkpoint' => $cp]);

                return false;
            }
            foreach ($page['rows'] as $row) {
                $item = $run->items()->where('source_external_id', $row['id'])->first();
                if (! $item && $run->items()->count() >= $cp['limit']) {
                    break;
                }
                $signatures = $segment === [] ? [] : [...($segment['ancestors'] ?? []), $this->planner->signature($segment)];
                if ($item) {
                    $item->update(['discovery_signatures' => array_values(array_unique([...($item->discovery_signatures ?? []), ...$signatures]))]);

                    continue;
                }
                $item = $run->items()->create(['source_external_id' => (string) $row['id'], 'listing_status' => $row['listing_status'] ?? null,
                    'discovery_signatures' => $signatures]);
                $this->tracker->enqueue($item);
            }
            $cp['segment_results'][$key] = ['total' => $page['total'], 'committed_page' => $page['page'], 'page_fingerprint' => $page['fingerprint'], 'last_page' => $page['last_page']];
            if (isset($page['prefix_fingerprint'])) {
                $cp['segment_results'][$key]['prefix_fingerprint'] = $page['prefix_fingerprint'];
            }
            $run->update(['checkpoint' => $cp]);

            return $run->items()->count() < $cp['limit'];
        });
    }

    public function explicitSelection(int $runId): void
    {
        DB::transaction(function () use ($runId) {
            $run = ScrapeRun::lockForUpdate()->findOrFail($runId);
            $cp = $run->checkpoint;
            $rows = [];
            if ($cp['sample_ids'] !== []) {
                foreach ($cp['sample_ids'] as $id) {
                    $rows[] = ['id' => $id, 'listing_status' => $cp['sample_listing_statuses'][$id] ?? null];
                }
            } else {
                $eligible = Document::where('source_id', $run->source_id)->whereIn('status', ['active', 'pending'])
                    ->where(function ($query) {
                        $query->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', now()->subDays(config('scraping.recheck_days')))
                            ->orWhere(fn ($pending) => $pending->where('status', 'pending')->whereDate('effective_at', '<=', now()->toDateString()));
                    })->orderByRaw('last_checked_at ASC NULLS FIRST')->limit($cp['limit']);
                foreach ($eligible->get() as $document) {
                    if ($document->external_id !== null) {
                        $rows[] = ['id' => $document->external_id, 'listing_status' => null];
                    }
                }
            }
            $this->selectPage($runId, ['rows' => $rows, 'page' => 1, 'total' => count($rows), 'fingerprint' => hash('sha256', json_encode(array_column($rows, 'id'))), 'last_page' => true]);
            $run->refresh();
            $cp = $run->checkpoint;
            $cp['discovery_finished'] = true;
            $cp['selection'] = $cp['sample_ids'] !== [] ? 'explicit_sample' : 'existing_document_recheck';
            $run->update(['checkpoint' => $cp]);
        });
    }

    public function discoveryResult(int $runId, array $result): void
    {
        DB::transaction(function () use ($runId, $result) {
            $run = ScrapeRun::lockForUpdate()->findOrFail($runId);
            $cp = $run->checkpoint;
            if (isset($cp['partition_pending'])) {
                $parent = $cp['partition_pending'];
                $signature = $this->planner->signature($parent['segment']);
                $children = $this->planner->split($parent['segment']);
                // A DFS frontier grows with date-tree depth, not corpus item count.
                array_splice($cp['segments'], $cp['segment_index'], 1, $children);
                $cp['partition_parents'][$signature] = $parent;
                unset($cp['partition_pending']);
                $cp['segment_results'] = [];
            } elseif ($result['complete']) {
                array_splice($cp['segments'], $cp['segment_index'], 1);
                $cp['segments_completed']++;
                $cp['segment_results'] = [];
                foreach (array_reverse($cp['partition_parents'], true) as $signature => $parent) {
                    $pending = array_any($cp['segments'], fn ($segment) => in_array($signature, $segment['ancestors'] ?? [], true));
                    if (! $pending) {
                        $count = $run->items()->whereJsonContains('discovery_signatures', $signature)->count();
                        if ($count !== $parent['total']) {
                            throw new RuntimeException('coverage_gap: partition union does not reconcile with identical parent query.');
                        }
                        unset($cp['partition_parents'][$signature]);
                    }
                }
            } else {
                $cp['bounded_stop'] = true;
                $cp['last_segment_result'] = $result;
            }
            $cp['discovery_finished'] = (! $result['complete'] && ! isset($parent)) || $run->items()->count() >= $cp['limit'] || $cp['segments'] === [];
            // Partition probes are incomplete by design and must dispatch the first child.
            if (isset($children)) {
                $cp['discovery_finished'] = false;
            }
            $run->update(['checkpoint' => $cp]);
            if (! $cp['discovery_finished']) {
                $this->push(new DiscoverDocumentsJob($runId));
            }
        });
    }

    public function outcome(int $runId, string $externalId, array $outcome, ?string $token = null): void
    {
        $this->tracker->finish($runId, $externalId, $outcome, $token);
    }

    public function discoveryFailure(int $runId, string $reason): void
    {
        DB::transaction(function () use ($runId, $reason) {
            $run = ScrapeRun::lockForUpdate()->findOrFail($runId);
            $cp = $run->checkpoint;
            $cp['discovery_failed'] = true;
            $cp['discovery_finished'] = true;
            $cp['coverage_gap'] = mb_substr($reason, 0, 500);
            $run->update(['checkpoint' => $cp, 'last_error' => mb_substr($reason, 0, 500)]);
        });
    }

    public function reconcile(int $runId): void
    {
        DB::transaction(function () use ($runId) {
            $run = ScrapeRun::lockForUpdate()->find($runId);
            if (! $run || ($run->status !== 'running' && $run->finished_at !== null)) {
                return;
            }
            $cp = $run->checkpoint;
            if (! ($cp['discovery_finished'] ?? false)) {
                return;
            }
            $queued = DB::table('jobs')->whereIn(DB::raw("payload::jsonb ->> 'displayName'"), [DiscoverDocumentsJob::class.'#'.$runId, ImportDocumentJob::class.'#'.$runId])->exists();
            if ($queued || $run->items()->whereNotIn('status', ScrapeRunItem::TERMINAL)->exists()) {
                return;
            }
            $this->tracker->counters($runId);
            $run->refresh();
            $cp['jobs_reconciled'] = true;
            $cp['languages_complete'] = ! $run->items()->whereNotIn('status', ['imported', 'unchanged', 'skipped_alias'])->exists();
            $cp['quality_passed'] = ! $run->items()->whereIn('status', ['review', 'failed'])->exists();
            $cp['coverage_verified'] = false; // CLI remains bounded; no corpus proof can be fabricated.
            $run->update(['checkpoint' => $cp, 'status' => ($cp['discovery_failed'] ?? false) ? 'failed' : 'completed_with_errors', 'finished_at' => now(),
                'last_error' => $run->last_error ?? 'Bounded/partial coverage; inspect the durable item ledger.']);
        });
    }

    public function resume(int $runId): ScrapeRun
    {
        if (! app(PilotCampaign::class)->allowsRun(ScrapeRun::findOrFail($runId))) {
            throw new PilotStoppedException('campaign resume requires an open original budget');
        }

        return DB::transaction(function () use ($runId) {
            $run = ScrapeRun::lockForUpdate()->findOrFail($runId);
            $cp = $run->checkpoint;
            if (($cp['version'] ?? null) !== 3 || $cp['limit'] > 30) {
                throw new InvalidArgumentException('Only bounded migrated/P3 runs can resume.');
            }
            $requeued = $this->tracker->recover($runId);
            $discoveryExists = DB::table('jobs')->whereRaw("payload::jsonb ->> 'displayName' = ?", [DiscoverDocumentsJob::class.'#'.$runId])->exists();
            if ((! $cp['discovery_finished'] || ($cp['discovery_failed'] ?? false)) && ! $discoveryExists) {
                unset($cp['discovery_failed']);
                $cp['discovery_finished'] = false;
                $this->push(new DiscoverDocumentsJob($runId));
            } elseif ($requeued === 0) {
                return $run;
            }
            $cp['jobs_reconciled'] = false;
            $run->update(['status' => 'running', 'finished_at' => null, 'checkpoint' => $cp]);

            return $run;
        });
    }

    public function summary(ScrapeRun $run): array
    {
        $items = $run->items();
        $counts = (clone $items)->selectRaw('status,count(*) AS count')->groupBy('status')->pluck('count', 'status')->map(fn ($n) => (int) $n)->all();
        $documentIds = (clone $items)->whereNotNull('document_id')->distinct()->pluck('document_id');
        $documents = Document::whereIn('id', $documentIds)->get();
        $summary = ['run_id' => $run->id, 'mode' => $run->mode, 'status' => $run->status, 'scope' => $run->checkpoint['scope'] ?? 'unknown',
            'discovered' => (clone $items)->count(), 'canonical_document_groups' => $documentIds->count() + (clone $items)->whereNull('document_id')->whereRaw("outcome->>'identity_verified' = 'true'")->distinct()->count(DB::raw("outcome->>'identity_key'")), 'canonical_documents_stored' => $documentIds->count(),
            'processed' => $run->processed_count, 'created' => $run->created_count, 'updated' => $run->updated_count,
            'imported' => $counts['imported'] ?? 0, 'unchanged' => $counts['unchanged'] ?? 0, 'skipped_alias' => $counts['skipped_alias'] ?? 0,
            'queued' => $counts['queued'] ?? 0, 'processing' => $counts['processing'] ?? 0, 'pending_retry' => $counts['retry_wait'] ?? 0,
            'failed' => $counts['failed'] ?? 0, 'technical_failed' => $counts['failed'] ?? 0, 'review_required' => $counts['review'] ?? 0,
            'active' => $documents->where('status', DocumentStatus::Active)->count(), 'pending' => $documents->where('status', DocumentStatus::Pending)->count(), 'inactive' => $documents->where('status', DocumentStatus::Inactive)->count(),
            'stored_content_count' => DB::table('document_contents')->whereIn('document_id', $documentIds)->count(), 'valid_content_count' => 0,
            'persisted_imported' => 0, 'persisted_unchanged' => 0, 'imported_with_needs_review' => 0, 'verified' => 0,
            'extraction_failed' => 0, 'failed_content_representations' => 0, 'stored_needs_review_content_count' => DB::table('document_contents')->whereIn('document_id', $documentIds)->where('quality_status', 'needs_review')->count(),
            'duration_seconds' => round($run->started_at->diffInSeconds($run->finished_at ?? now()), 3),
            'coverage_verified' => $run->checkpoint['coverage_verified'] ?? false, 'gates' => array_intersect_key($run->checkpoint, array_flip(['coverage_verified', 'discovery_finished', 'jobs_reconciled', 'languages_complete', 'quality_passed'])),
            'open_items' => (clone $items)->whereNotIn('status', ScrapeRunItem::TERMINAL)->count(), 'html' => 0, 'pdf' => 0, 'ocr' => 0, 'languages' => [], 'required_language_failures' => 0,
            'source_status_counts' => ['active' => 0, 'pending' => 0, 'inactive' => 0]];
        $validContents = [];
        foreach ((clone $items)->orderBy('id')->cursor() as $item) {
            $outcome = $item->outcome ?? [];
            if ($item->status !== 'skipped_alias') {
                $summary['persisted_imported'] += ($outcome['import_outcome'] ?? null) === 'imported' ? 1 : 0;
                $summary['persisted_unchanged'] += ($outcome['import_outcome'] ?? null) === 'unchanged' ? 1 : 0;
                $summary['imported_with_needs_review'] += ($outcome['persisted_content_count'] ?? 0) > 0 && ($outcome['quality_status'] ?? null) === 'needs_review' ? 1 : 0;
                $summary['verified'] += ($outcome['complete'] ?? false) === true ? 1 : 0;
                $summary['extraction_failed'] += ($outcome['import_outcome'] ?? null) === 'extraction_failed' ? 1 : 0;
            }
            if (isset($outcome['source_status'], $summary['source_status_counts'][$outcome['source_status']])) {
                $summary['source_status_counts'][$outcome['source_status']]++;
            }
            if (in_array($outcome['format'] ?? null, ['html', 'pdf', 'ocr'], true)) {
                $summary[$outcome['format']]++;
            }
            foreach ($item->language_results ?? [] as $language => $result) {
                if ($item->status !== 'skipped_alias' && ($result['quality_status'] ?? null) === 'extraction_failed') {
                    $summary['failed_content_representations']++;
                }
                if ($result['state'] === 'valid' && $item->status !== 'skipped_alias') {
                    $validContents[$item->document_id.':'.$language] = true;
                    $summary['languages'][$language] = ($summary['languages'][$language] ?? 0) + 1;
                } elseif ($result['state'] === 'review') {
                    $summary['required_language_failures']++;
                }
            }
        }

        $summary['valid_content_count'] = count($validContents);
        $summary['discovery_failed'] = $run->checkpoint['discovery_failed'] ?? false;
        $summary['discovery_error'] = $summary['discovery_failed'] ? $run->last_error : null;
        $summary['pending_framework_jobs'] = DB::table('jobs')->whereIn(DB::raw("payload::jsonb ->> 'displayName'"), [DiscoverDocumentsJob::class.'#'.$run->id, ImportDocumentJob::class.'#'.$run->id])->count();

        return $summary;
    }
}
