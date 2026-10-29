<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentContent;
use App\Models\DocumentSource;
use App\Services\Documents\ContentQuality;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecheckDocumentQuality extends Command
{
    protected $signature = 'documents:quality-recheck {source=lex} {--status=needs_review} {--limit=20} {--dry-run}';

    protected $description = 'Revalidate bounded stored content using retained extraction evidence; no HTTP or extraction';

    public function handle(ContentQuality $quality): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $status = $this->option('status');
        if ($limit === false || $limit < 1 || $limit > 30 || ! in_array($status, ['verified', 'needs_review', 'extraction_failed'], true)) {
            $this->error('Use --limit=1..30 and --status=verified|needs_review|extraction_failed.');

            return self::FAILURE;
        }
        $source = DocumentSource::where('slug', $this->argument('source'))->firstOrFail();
        $ids = DocumentContent::whereHas('document', fn ($q) => $q->where('source_id', $source->id))->where('quality_status', $status)->orderBy('id')->limit($limit)->pluck('id');
        $summary = ['source' => $source->slug, 'requested_status' => $status, 'limit' => $limit, 'dry_run' => (bool) $this->option('dry-run'),
            'selected' => $ids->count(), 'checked' => 0, 'verified' => 0, 'needs_review' => 0, 'extraction_failed' => 0, 'protected' => 0, 'changed' => 0, 'skipped_concurrent_change' => 0];
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $source, $status, $quality, &$summary) {
                DocumentSource::lockForUpdate()->findOrFail($source->id);
                $record = DocumentContent::find($id);
                if ($record === null) {
                    $summary['skipped_concurrent_change']++;

                    return;
                }
                $document = Document::lockForUpdate()->findOrFail($record->document_id);
                $record = DocumentContent::lockForUpdate()->findOrFail($id);
                if ($record->quality_status !== $status || $document->source_id !== $source->id) {
                    $summary['skipped_concurrent_change']++;

                    return;
                }
                $metadata = $record->extraction_metadata;
                $candidate = ['title' => $record->title, 'content' => $record->content,
                    'quality' => $metadata['quality'] ?? ['issues' => ['legacy_quality_unproven']], 'provenance' => $metadata['provenance'] ?? []];
                $at = now()->toImmutable();
                $assessment = $quality->evaluate($candidate, $record, $at);
                $summary['checked']++;
                $summary[$assessment['quality_status']]++;
                $summary['protected'] += $assessment['stage'] ? 1 : 0;
                if (! $summary['dry_run']) {
                    if ($assessment['usable']) {
                        // A local recheck is not another source fetch. Preserve the original fetch timestamp.
                        $summary['changed'] += $quality->persist($record, $candidate, $assessment, $record->title, $metadata['source_url'] ?? null, $at, sourceFetch: false) ? 1 : 0;
                    } else {
                        $metadata['last_failed_recheck'] = ['at' => $at->toAtomString(), 'quality_status' => 'extraction_failed', 'issues' => $assessment['quality_issues']];
                        $record->extraction_metadata = $metadata;
                        $record->save();
                    }
                }
            });
        }
        $summary['note'] = 'Local evidence recheck only; no OCR certification, source fetch or deletion. Extraction failures have no empty content row.';
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
