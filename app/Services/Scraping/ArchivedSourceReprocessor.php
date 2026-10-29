<?php

namespace App\Services\Scraping;

use App\Models\Document;
use App\Models\DocumentSource;
use App\Models\SourceArchiveObservation;
use App\Scraping\LexUz\HtmlMarkdownParser;
use App\Scraping\LexUz\LexUzParser;
use App\Scraping\LexUz\PdfTextExtractor;
use App\Services\Documents\DocumentImporter;
use App\Services\Documents\DocumentValidator;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ArchivedSourceReprocessor
{
    public function __construct(private SourceArchive $archive, private DocumentImporter $importer, private DocumentValidator $validator) {}

    /** One latest graph per canonical document, with a bounded selection scan and no source traffic. */
    public function select(DocumentSource $source, int $limit, ?int $documentId = null, ?string $sourceId = null): array
    {
        if ($limit < 1 || $limit > (int) config('source_archive.max_reprocess_limit')) {
            throw new ArchiveException('reprocess_limit_must_be_1_to_30');
        }
        $query = SourceArchiveObservation::where('document_source_id', $source->id)->whereNotNull('document_id')
            ->where('archive_status', 'archived')->whereRaw("jsonb_typeof(context->'snapshot') = 'object'");
        if ($documentId !== null) {
            $query->where('document_id', $documentId);
        }
        if ($sourceId !== null) {
            $query->whereHas('document', fn ($q) => $q->whereHas('identifiers', fn ($ids) => $ids->where('source_id', $source->id)->where('external_id', $sourceId)));
        }
        $selected = [];
        foreach ($query->orderByDesc('id')->limit($limit * 32)->get() as $observation) {
            if (! isset($selected[$observation->document_id])) {
                $selected[$observation->document_id] = $observation;
            }
            if (count($selected) === $limit) {
                break;
            }
        }

        return array_values($selected);
    }

    public function reprocess(SourceArchiveObservation $observation, bool $dryRun = false): array
    {
        $started = hrtime(true);
        $outcome = ['observation_id' => $observation->id, 'document_id' => $observation->document_id,
            'dry_run' => $dryRun, 'network_requests' => 0, 'state' => 'failed'];
        try {
            [$candidate, $at] = $this->candidate($observation);
            $document = Document::findOrFail($observation->document_id);
            if ($document->source_id !== $observation->document_source_id) {
                throw new ArchiveException('archive_document_source_mismatch');
            }
            $result = null;
            try {
                DB::transaction(function () use ($observation, $candidate, $at, $dryRun, &$result) {
                    $result = $this->importer->import($observation->document_source_id, $candidate, $at);
                    if ($result['document_id'] !== $observation->document_id || $result['action'] === 'created') {
                        throw new ArchiveException('archive_canonical_identity_changed');
                    }
                    if ($dryRun) {
                        throw new ArchiveDryRun;
                    }
                });
            } catch (ArchiveDryRun) {
                // The exact importer transaction was evaluated, then rolled back intentionally.
            }
            $outcome += ['result' => $result, 'observed_at' => $at->format(DATE_ATOM)];
            $outcome['state'] = 'processed';
        } catch (ArchiveException $e) {
            $outcome['reason'] = $e->reason;
        } catch (ValidationException $e) {
            $outcome['reason'] = 'validation_rejected';
            $outcome['validation_errors'] = $e->errors();
        } catch (Throwable $e) {
            $outcome['reason'] = 'reprocess_technical_failure';
            $outcome['error_class'] = $e::class;
        }
        $outcome['duration_seconds'] = round((hrtime(true) - $started) / 1e9, 6);
        if (! $dryRun) {
            DB::transaction(function () use ($observation, $outcome) {
                $current = SourceArchiveObservation::lockForUpdate()->findOrFail($observation->id);
                $context = $current->context;
                $context['reprocess_attempts'] = (int) ($context['reprocess_attempts'] ?? 0) + 1;
                $context['last_reprocess'] = ['at' => now()->toAtomString(), ...$outcome];
                $current->update(['context' => $context]);
            });
        }

        return $outcome;
    }

    /** Re-run existing extraction against original bytes and cards, retaining original observation time. */
    private function candidate(SourceArchiveObservation $primary): array
    {
        if ($primary->archive_status !== 'archived' || $primary->document_id === null) {
            throw new ArchiveException('archive_not_usable');
        }
        $snapshot = $primary->context['snapshot'] ?? null;
        $pages = $primary->context['pages'] ?? [];
        $at = $primary->context['observed_at'] ?? null;
        if (! is_array($snapshot) || $pages === [] || ! is_string($at)) {
            throw new ArchiveException('archive_graph_snapshot_missing');
        }
        $identity = $this->validator->identity($snapshot['identity'] ?? [], $snapshot['entry_id'] ?? '');
        if (count($pages) !== count($identity)) {
            throw new ArchiveException('archive_graph_incomplete');
        }
        $responses = [];
        foreach (SourceArchiveObservation::with('blob')->where('capture_key', $primary->capture_key)->get() as $observation) {
            if ($observation->document_source_id !== $primary->document_source_id || $observation->document_id !== $primary->document_id
                || $observation->archive_status !== 'archived' || $observation->blob === null) {
                throw new ArchiveException('archive_graph_incomplete');
            }
            $url = $this->archive->sourceUrl($observation->source_url);
            $responses[$url] = ['bytes' => $this->archive->read($observation->blob), 'content_type' => $observation->content_type];
        }
        $http = new ArchivedLexHttpClient($responses);
        $parser = new LexUzParser($http, app(HtmlMarkdownParser::class), app(PdfTextExtractor::class));
        $candidate = $snapshot;
        $candidate['contents'] = [];
        $candidate['identity'] = [];
        $quality = [];
        foreach ($identity as $id => $oldNode) {
            $page = $pages[$id] ?? null;
            if (! is_array($page) || ($page['url'] ?? null) !== $oldNode['source_url'] || ! is_array($page['card'] ?? null)) {
                throw new ArchiveException('archive_graph_page_missing');
            }
            $html = $http->get($page['url'])->body();
            $links = $parser->links($html, (string) $id, $page['card']);
            $declared = $parser->language($page['card']);
            foreach ($links as $requested => $link) {
                if ((string) $link['id'] === (string) $id && $requested !== $declared) {
                    throw new ArchiveException('archive_source_language_fallback');
                }
            }
            $parsed = $parser->content($html, $page['card']);
            $language = $parsed['language'];
            $availability = $parser->availability($html, $page['card'], $parsed);
            if ($language !== $declared) {
                $availability['metadata']['physical_language'] = $language;
            }
            $node = ['external_id' => (string) $id, 'language' => $language, 'source_url' => $page['url'],
                'availability' => $parsed['metadata_only'] ? 'metadata_only' : 'full_text', 'availability_evidence' => $availability,
                'links' => array_values(array_map(fn ($link) => (string) $link['id'], $links)), 'requisites' => $parser->requisites($page['card'])];
            if ($language !== $oldNode['language'] || ! $this->validator->equivalent($node['requisites'], $oldNode['requisites'])) {
                throw new ArchiveException('archive_identity_evidence_changed');
            }
            $candidate['identity'][] = $node;
            $quality[$language] = array_diff_key($parsed['quality'], ['timings' => true]);
            if (! $parsed['metadata_only']) {
                $candidate['contents'][$language] = ['title' => $page['card']['Наименование акта'], 'content' => $parsed['content'], 'quality' => $parsed['quality'],
                    'provenance' => ['source_url' => $page['url'], 'source_html_sha256' => hash('sha256', $html),
                        'parser' => LexUzParser::class, 'parser_sha256' => hash_file('sha256', app_path('Scraping/LexUz/LexUzParser.php')),
                        'pdf_extractor_sha256' => hash_file('sha256', app_path('Scraping/LexUz/PdfTextExtractor.php')),
                        'archive_capture_key' => $primary->capture_key, 'archive_observation_id' => $primary->id, 'reprocessed_from_archive' => true]];
            }
        }
        $this->validator->identity($candidate['identity'], $candidate['entry_id']);
        if (isset($candidate['metadata']['lex'])) {
            $candidate['metadata']['lex']['quality'] = $quality;
        }

        return [$candidate, new DateTimeImmutable($at)];
    }
}

/** Internal transaction rollback marker; never represents an extraction or database failure. */
class ArchiveDryRun extends \RuntimeException {}
