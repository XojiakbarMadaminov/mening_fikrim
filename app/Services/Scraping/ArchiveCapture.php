<?php

namespace App\Services\Scraping;

use App\Models\SourceArchiveObservation;
use App\Scraping\LexUz\LexUzParser;
use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use Throwable;

/** Request-scoped bounded buffer. Only finish writes DB; the source adapter never does. */
class ArchiveCapture
{
    private ?array $scope = null;

    private array $responses = [];

    private int $bytes = 0;

    public function __construct(private SourceArchive $archive) {}

    public function begin(int $sourceId, string $entryId, ?string $campaignId = null, ?DateTimeInterface $observedAt = null): void
    {
        $this->scope = ['source_id' => $sourceId, 'entry_id' => $entryId, 'campaign_id' => $campaignId,
            'capture_key' => (string) Str::uuid(), 'observed_at' => $observedAt?->format(DateTimeInterface::ATOM)];
        $this->responses = [];
        $this->bytes = 0;
    }

    public function capture(string $url, Response $response, bool $pdf = false): void
    {
        if ($this->scope === null || ! $response->successful()
            || (! $pdf && ! preg_match('~^https://lex\.uz/(?:uz/)?docs/-?\d+$~D', $url))) {
            return;
        }
        try {
            $this->archive->sourceUrl($url);
        } catch (ArchiveException) {
            // Do not persist an unsafe URL, even in failure diagnostics.
            return;
        }
        $body = $response->body();
        $size = strlen($body);
        $previous = strlen($this->responses[$url]['bytes'] ?? '');
        $keep = $this->archive->enabled();
        $reason = null;
        if ($size > (int) config('source_archive.max_file_bytes')) {
            $keep = false;
            $reason = 'archive_file_size_invalid';
        } elseif ($keep && $this->bytes - $previous + $size > (int) config('source_archive.max_capture_bytes')) {
            $keep = false;
            $reason = 'archive_capture_byte_budget_exhausted';
        }
        $this->bytes -= $previous;
        $this->responses[$url] = ['source_url' => $url, 'format' => $pdf ? 'pdf' : 'html', 'file_size' => $size,
            'content_type' => mb_substr($response->header('Content-Type') ?? 'application/octet-stream', 0, 150),
            'sha256' => hash('sha256', $body), 'fetched_at' => now()->toAtomString(), 'bytes' => $keep ? $body : null, 'failure_reason' => $reason];
        $this->bytes += $keep ? $size : 0;
    }

    public function finish(?array $candidate = null, ?array $result = null, ?Throwable $failure = null, ?DateTimeInterface $observedAt = null): array
    {
        $summary = ['archived' => 0, 'disabled' => 0, 'privacy_blocked' => 0, 'failed' => 0, 'bytes' => 0,
            'observation_ids' => [], 'errors' => [], 'stop_reason' => null];
        if ($this->scope === null) {
            return $summary;
        }
        $scope = $this->scope;
        $responses = $this->responses;
        $this->scope = null;
        $this->responses = [];
        $this->bytes = 0;
        $pages = $candidate['diagnostics']['archive_pages'] ?? [];
        $snapshot = $candidate === null ? null : array_intersect_key($candidate, array_flip([
            'entry_id', 'title', 'source_url', 'document_type', 'document_number', 'issued_at', 'published_at',
            'status_evidence', 'text_effective_at', 'identity', 'metadata',
        ]));
        $observed = $observedAt?->format(DateTimeInterface::ATOM) ?? $scope['observed_at'] ?? now()->toAtomString();
        $languageUrls = [];
        foreach ($candidate['identity'] ?? [] as $node) {
            $languageUrls[$node['source_url']] = ['id' => $node['external_id'], 'language' => $node['language']];
        }
        foreach ($responses as $url => $response) {
            if ($response['format'] === 'html' && $response['bytes'] !== null && isset($languageUrls[$url])) {
                try {
                    $pdfUrl = app(LexUzParser::class)->pdfUrl($response['bytes']);
                    if ($pdfUrl !== null) {
                        $languageUrls[$pdfUrl] = $languageUrls[$url];
                    }
                } catch (Throwable) {
                    // A malformed viewer cannot manufacture an association for its PDF.
                }
            }
        }
        $primary = true;
        foreach ($responses as $url => $response) {
            $blob = null;
            $reason = $response['failure_reason'];
            $status = $this->archive->enabled() ? 'failed' : 'disabled';
            try {
                if ($status === 'disabled') {
                    $reason = 'retention_not_enabled_or_confirmed';
                } elseif ($reason === null) {
                    $blob = $this->archive->retain($response['bytes'], $response['format'], $response['content_type']);
                    $status = 'archived';
                }
            } catch (ArchiveException $e) {
                $reason = $e->reason;
                $status = $reason === 'html_contains_unretained_private_state' ? 'privacy_blocked' : 'failed';
            } catch (Throwable) {
                $reason = 'archive_persistence_failed';
                $status = 'failed';
            }
            if ($reason === 'archive_quota_exhausted' && config('source_archive.quota_failure_policy') === 'stop_campaign') {
                $summary['stop_reason'] = 'archive_quota_exhausted';
            }
            $summary[$status]++;
            $summary['bytes'] += $blob?->file_size ?? 0;
            $context = ['campaign_id' => $scope['campaign_id'], 'entry_id' => $scope['entry_id'], 'observed_at' => $observed,
                'parser' => LexUzParser::class, 'parser_sha256' => hash_file('sha256', app_path('Scraping/LexUz/LexUzParser.php')),
                'extractor_sha256' => hash_file('sha256', app_path('Scraping/LexUz/PdfTextExtractor.php'))];
            if ($primary) {
                $context += ['snapshot' => $snapshot, 'pages' => $pages, 'import_outcome' => $result === null ? null : array_intersect_key($result, array_flip(['document_id', 'action', 'import_outcome', 'quality_status', 'complete'])),
                    'failure_class' => $failure === null ? null : $failure::class];
                $primary = false;
            }
            try {
                $observation = SourceArchiveObservation::create([
                    'observation_key' => hash('sha256', $scope['capture_key']."\n".$url."\n".$response['sha256']),
                    'capture_key' => $scope['capture_key'], 'blob_id' => $blob?->id,
                    'document_source_id' => $scope['source_id'], 'document_id' => $result['document_id'] ?? null,
                    'source_external_id' => $languageUrls[$url]['id'] ?? null, 'language' => $languageUrls[$url]['language'] ?? null,
                    'source_url' => $url, 'observed_sha256' => $response['sha256'], 'file_size' => $response['file_size'],
                    'format' => $response['format'], 'content_type' => $response['content_type'], 'archive_status' => $status,
                    'failure_reason' => $reason, 'fetched_at' => $response['fetched_at'], 'context' => $context,
                ]);
                $summary['observation_ids'][] = $observation->id;
            } catch (Throwable) {
                $summary['errors'][] = 'archive_observation_write_failed';
            }
            if ($status === 'failed' || $status === 'privacy_blocked') {
                $summary['errors'][] = $reason;
            }
        }
        $summary['errors'] = array_values(array_unique($summary['errors']));

        return $summary;
    }
}
