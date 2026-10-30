<?php

use App\Models\Document;
use App\Models\DocumentIdentifier;
use App\Models\DocumentSource;
use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexRequestException;
use App\Scraping\LexUz\LexUzParser;
use App\Scraping\LexUz\LexUzScraper;
use App\Scraping\LexUz\PdfTextExtractor;
use App\Scraping\LexUz\SourceReviewException;
use App\Services\Documents\ContentQuality;
use App\Services\Scraping\ArchiveCapture;
use App\Services\Scraping\DurableCampaign;
use Illuminate\Validation\ValidationException;
use Symfony\Component\DomCrawler\Crawler;

require __DIR__.'/runtime.php';
p6Guard();
$action = $argv[1] ?? '';
if (! in_array($action, ['policy', 'execute'], true)) {
    throw new RuntimeException('Explicit policy or execute required.');
}
$campaign = app(DurableCampaign::class);
$id = 'p6-validation-20261029';
$bounds = ['p6-validation-v1', 30];
config(['logging.channels.scraping.driver' => 'single', 'logging.channels.scraping.path' => __DIR__.'/requests.jsonl']);
$source = DocumentSource::where('slug', 'lex')->firstOrFail();
$http = app(LexHttpClient::class);
$parser = app(LexUzParser::class);
$capture = app(ArchiveCapture::class);
$started = hrtime(true);
try {
    if (! $campaign->exists()) {
        $campaign->create($id, 2000, 200, 0, ['campaign_type' => 'validation', 'max_partitions' => 31,
            'max_discovered_ids' => 3000, 'pdf_sample_cap' => 30, 'archive_quota_bytes' => 2147483648]);
    } else {
        $campaign->claim($id);
    }
    $campaign->assertOpen();
    $progress = $campaign->loadCheckpoint('validation', $bounds)['progress'] ?? ['policies' => [], 'samples' => [], 'targets' => null];
    if ($action === 'policy') {
        foreach (['https://lex.uz/uz/axborot', 'https://lex.uz/agreement', 'https://lex.uz/robots.txt'] as $url) {
            if (isset($progress['policies'][$url])) {
                continue;
            }
            $http->context('p6-policy');
            try {
                $response = $http->get($url);
                $text = (new Crawler($response->body()))->filter('body')->text('', true);
                $progress['policies'][$url] = ['at' => now()->toAtomString(), 'status' => $response->status(),
                    'sha256' => hash('sha256', $response->body()), 'public_visible_text' => mb_substr($text, 0, 24000)];
            } catch (LexRequestException $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
                $progress['policies'][$url] = ['at' => now()->toAtomString(), 'status' => 404, 'permission_inference' => 'none'];
            }
            $campaign->saveCheckpoint('validation', $bounds, ['nodes' => [], 'progress' => $progress]);
            p6Save('source_policy', $progress['policies']);
        }
    } else {
        $assessment = p6Read('source_policy_assessment');
        if (! ($assessment['bounded_collection_allowed'] ?? false) || count($progress['policies']) !== 3) {
            throw new RuntimeException('Current source policy assessment required before document traffic.');
        }
        // Permission remains unconfirmed for raw retention. Never enable it implicitly.
        if (config('source_archive.enabled') || config('source_archive.retention_confirmed')) {
            throw new RuntimeException('This pilot uses explicitly disabled raw retention pending permission.');
        }
        if ($progress['targets'] === null) {
            $targets = ['8557098', '8554315', '8554432', '8554353', '8554326', '8554321', '8345596', '-8553692', '-8553258', '4797522'];
            $knownDocuments = DocumentIdentifier::where('source_id', $source->id)->whereIn('external_id', $targets)->pluck('document_id')->all();
            foreach (Document::where('source_id', $source->id)->whereNotIn('id', $knownDocuments)->orderBy('id')->get() as $document) {
                if (in_array($document->external_id, ['1587862', '-1587862', '1587860', '8354875', '-8345596'], true)) {
                    continue;
                }
                $targets[] = $document->external_id;
                if (count($targets) === 30) {
                    break;
                }
            }
            $progress['targets'] = $targets;
            $campaign->saveCheckpoint('validation', $bounds, ['nodes' => [], 'progress' => $progress]);
        }
        // One bounded retry of a real, now-supported source label after the controlled worker restart.
        $targets = $progress['targets'];
        foreach ($progress['samples'] as $previousId => $previous) {
            if (($previous['diagnostic_error'] ?? null) === 'Unknown source document language: Инглизча'
                && ! isset($progress['language_rechecks'][(string) $previousId])) {
                $targets[] = (string) $previousId;
            }
        }
        foreach ($targets as $sourceId) {
            $sourceId = (string) $sourceId;
            $prior = $progress['samples'][$sourceId] ?? null;
            $languageRetry = ($prior['diagnostic_error'] ?? null) === 'Unknown source document language: Инглизча'
                && ! isset($progress['language_rechecks'][$sourceId]);
            if ($prior !== null && ! $languageRetry) {
                continue;
            }
            $campaign->selectSources([$sourceId]);
            $http->context('p6-pdf-'.$sourceId);
            $capture->begin($source->id, $sourceId, $id);
            $before = $campaign->state()['used'];
            $sampleStarted = hrtime(true);
            $candidate = null;
            $documentId = null;
            $failure = null;
            $samples = [];
            try {
                $candidate = app(LexUzScraper::class)->fetch($sourceId);
                $documentId = DocumentIdentifier::where('source_id', $source->id)->where('external_id', $sourceId)->value('document_id');
                foreach ($candidate['contents'] as $language => $content) {
                    $quality = $content['quality'];
                    if (in_array($quality['format'], ['pdf', 'ocr'], true)) {
                        $assessment = app(ContentQuality::class)->evaluate($content, null, now());
                        $samples[] = ['source_id' => $sourceId, 'canonical_document_id' => $documentId, 'source_format' => $quality['format'],
                            'page_count' => $quality['pages_expected'] ?? null, 'language' => $language, 'text_char_count' => mb_strlen($content['content']),
                            'sha256' => $quality['provenance']['source_sha256'] ?? null, 'quality_status' => $assessment['usable'] ? $assessment['quality_status'] : null,
                            'extraction_outcome' => $assessment['usable'] ? 'succeeded' : 'failed',
                            'quality_issues' => $assessment['quality_issues'], 'timings' => $quality['timings'] ?? [], 'persistence_status' => 'diagnostic_only_no_import'];
                    }
                }
                // Follow only PDF viewer links actually exposed by this source response.
                $mainUrl = 'https://lex.uz/uz/docs/'.$sourceId;
                $html = $http->get($mainUrl)->body(); // same-fetch cache, not another HTTP request
                $viewerLinks = [];
                preg_match_all('~/(?:uz/)?pdfs/-?\d+~', html_entity_decode($html, ENT_QUOTES | ENT_HTML5), $matches);
                foreach (array_unique($matches[0]) as $viewer) {
                    if (basename($viewer) === $sourceId) {
                        $viewerLinks[] = $http->absolute($viewer);
                    }
                }
                $card = $candidate['diagnostics']['archive_pages'][$sourceId]['card'];
                if ($samples === [] && $viewerLinks !== []) {
                    $viewer = $viewerLinks[0];
                    $viewerHtml = $http->get($viewer)->body();
                    $resource = $parser->pdfUrl($viewerHtml);
                    if ($resource !== null) {
                        $response = $http->get($resource, true);
                        $row = $card['accepting_bodies'][0] ?? [];
                        $parsed = app(PdfTextExtractor::class)->extract($response->body(), $response->header('Content-Type'), $parser->language($card),
                            ['document_number' => $row[5] ?? '', 'issued_date' => $row[4] ?? '', 'registration_number' => $card['Регистрационный № МЮ'] ?? '']);
                        $quality = $parsed['quality'];
                        $assessment = app(ContentQuality::class)->evaluate($parsed, null, now());
                        $samples[] = ['source_id' => $sourceId, 'canonical_document_id' => $documentId, 'source_format' => $quality['format'],
                            'source_url' => $mainUrl, 'viewer_url' => $viewer, 'resource_url' => $resource,
                            'file_size' => strlen($response->body()), 'sha256' => hash('sha256', $response->body()),
                            'page_count' => $quality['pages_expected'], 'language' => $parsed['language'], 'text_char_count' => mb_strlen($parsed['content']),
                            'quality_status' => $assessment['usable'] ? $assessment['quality_status'] : null, 'quality_issues' => $assessment['quality_issues'], 'timings' => $quality['timings'],
                            'extraction_outcome' => $assessment['usable'] ? 'succeeded' : 'failed',
                            'persistence_status' => 'alternate_representation_diagnostic_only_no_import'];
                    }
                }
                foreach ($samples as &$sample) {
                    if (! isset($sample['file_size'])) {
                        foreach ($candidate['diagnostics']['archive_pages'] as $page) {
                            if (($page['pdf_url'] ?? null) !== null) {
                                $response = $http->get($page['pdf_url'], true);
                                if (hash('sha256', $response->body()) === $sample['sha256']) {
                                    $sample['file_size'] = strlen($response->body());
                                    $sample['resource_url'] = $page['pdf_url'];
                                    break;
                                }
                            }
                        }
                    }
                }
                unset($sample);
            } catch (SourceReviewException|ValidationException $e) {
                // Identity uncertainty remains diagnostic only; never guess a canonical association.
                $failure = $e;
            } catch (Throwable $e) {
                $failure = $e;
                throw $e;
            } finally {
                $archive = $capture->finish($candidate, isset($documentId) && $candidate !== null ? ['document_id' => $documentId] : null, $failure);
                $http->endFlow();
                $progress['samples'][$sourceId] = ['samples' => $samples, 'source_id' => $sourceId, 'archive' => $archive,
                    'diagnostic_error' => $failure?->getMessage(), 'duration_seconds' => (hrtime(true) - $sampleStarted) / 1e9,
                    'http_requests' => $campaign->state()['used'] - $before, 'at' => now()->toAtomString()];
                if ($languageRetry) {
                    $progress['samples'][$sourceId]['previous_diagnostic'] = $prior;
                    $progress['language_rechecks'][$sourceId] = ['at' => now()->toAtomString(), 'succeeded' => $failure === null];
                }
                p6Save('pdf_ocr_verification', $progress['samples']);
                if ($archive['stop_reason'] || $archive['failed'] || in_array('archive_observation_write_failed', $archive['errors'], true)) {
                    throw new RuntimeException($archive['stop_reason'] ?? 'archive_capture_failure');
                }
            }
            $campaign->saveCheckpoint('validation', $bounds, ['nodes' => [], 'progress' => $progress]);
            echo 'PDF_CHECK '.$sourceId.' pdf_samples='.count($samples).' requests='.$progress['samples'][$sourceId]['http_requests']."\n";
            flush();
            p6Audit('validation_after_'.$sourceId);
        }
        $audit = p6Audit('validation_final');
        $campaign->auditedStage($audit['errors']);
        $campaign->complete();
    }
} catch (Throwable $error) {
    p6FinishFailure($error);
} finally {
    $execution = ['at' => now()->toAtomString(), 'action' => $action, 'controller_wall_seconds' => (hrtime(true) - $started) / 1e9, 'campaign' => $campaign->state()];
    p6Save('validation_execution', $execution);
    file_put_contents(__DIR__.'/controller_invocations.jsonl', json_encode($execution)."\n", FILE_APPEND);
    $campaign->release();
}
