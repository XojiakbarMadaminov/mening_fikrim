<?php

namespace App\Services\Documents;

use App\Models\DocumentContent;
use DateTimeImmutable;
use DateTimeInterface;

/** Collection admission is independent of the existing strict quality validator. */
class ContentQuality
{
    public function __construct(private DocumentValidator $validator) {}

    public function evaluate(?array $candidate, ?DocumentContent $previous, DateTimeInterface $checkedAt): array
    {
        $result = $this->validator->content($candidate, $previous?->content);
        $text = $candidate['content'] ?? null;
        $usable = is_string($text) && mb_check_encoding($text, 'UTF-8') && ! str_contains($text, "\0");
        $letters = $usable ? preg_replace('/[^\p{L}\p{N}]/u', '', $text) : '';
        // Reject empty, punctuation-only and tiny/repeated-glyph artifacts, not legal-quality uncertainties.
        $usable = $usable && mb_strlen($letters) >= 10 && count(array_unique(mb_str_split(mb_strtolower($letters)))) >= 4;
        if ($candidate !== null && ! $usable && ! in_array('invalid_text', $result['errors'], true)) {
            $result['errors'][] = 'unusable_text';
        }
        $pendingAt = $previous?->pending_candidate['observed_at'] ?? null;
        $stale = ($previous?->last_fetched_at !== null && $previous->last_fetched_at > $checkedAt)
            || ($pendingAt !== null && new DateTimeImmutable($pendingAt) > $checkedAt);
        if ($stale) {
            $result['errors'][] = 'stale_fetch';
        }
        $issues = array_values(array_unique([...($candidate['quality']['issues'] ?? []), ...$result['errors']]));
        $status = ! $usable ? 'extraction_failed' : ($result['errors'] === [] ? 'verified' : 'needs_review');
        $stage = $usable && ! $stale && $previous !== null && $status === 'needs_review'
            && ($previous->quality_status === 'verified' || in_array('suspected_truncation', $result['errors'], true));

        return $result + ['usable' => $usable, 'quality_status' => $status, 'quality_issues' => $issues, 'stage' => $stage,
            'persistence' => ! $usable ? 'extraction_failed' : ($stale ? 'stale_ignored' : ($stage ? 'candidate' : 'stored'))];
    }

    public function persist(DocumentContent $content, array $candidate, array $assessment, string $fallbackTitle, ?string $sourceUrl, DateTimeInterface $at, bool $sourceFetch = true): bool
    {
        if (! $assessment['usable'] || $assessment['persistence'] === 'stale_ignored') {
            return false;
        }
        $metadata = array_replace($content->extraction_metadata ?? [], ['source_url' => $sourceUrl, 'raw_source_retained' => false, 'content_sha256' => $assessment['hash'],
            'quality' => array_diff_key($candidate['quality'] ?? [], ['timings' => true]), 'provenance' => $candidate['provenance'] ?? []]);
        if ($sourceFetch) {
            unset($metadata['legacy_admission']);
        }
        $title = is_string($candidate['title'] ?? null) && trim($candidate['title']) !== '' ? $candidate['title'] : $fallbackTitle;
        if ($assessment['stage']) {
            $pending = ['title' => $title, 'content' => $assessment['content'], 'content_hash' => $assessment['hash'],
                'quality_status' => 'needs_review', 'quality_issues' => $assessment['quality_issues'], 'extraction_metadata' => $metadata,
                'observed_at' => $at->format(DateTimeInterface::ATOM),
                'protected_content_hash' => $content->content_hash, 'reason' => $content->quality_status === 'verified' ? 'verified_content_protected' : 'suspected_truncation'];
            $old = $content->pending_candidate;
            $changed = $old === null || $old['content_hash'] !== $pending['content_hash'] || ! $this->validator->equivalent($old['quality_issues'], $pending['quality_issues']);
            $content->pending_candidate = $pending;
            $content->save();

            return $changed;
        }
        $content->fill(['title' => $title, 'content' => $assessment['content'], 'content_hash' => $assessment['hash'],
            'quality_status' => $assessment['quality_status'], 'quality_issues' => $assessment['quality_issues']]);
        $changed = ! $content->exists || $content->isDirty(['title', 'content', 'content_hash', 'quality_status'])
            || ! $this->validator->equivalent($content->getOriginal('quality_issues') ?? [], $assessment['quality_issues']);
        $content->extraction_metadata = $metadata;
        $content->quality_checked_at = $at;
        if ($sourceFetch) {
            $content->last_fetched_at = $at;
        }
        // A newer verified extraction resolves a held candidate; retain its diagnostic fingerprint.
        if ($sourceFetch && $assessment['quality_status'] === 'verified' && $content->pending_candidate !== null) {
            $metadata['resolved_candidate'] = array_intersect_key($content->pending_candidate, array_flip(['content_hash', 'quality_issues', 'observed_at', 'reason']));
            $metadata['resolved_candidate']['resolved_by_hash'] = $assessment['hash'];
            $content->extraction_metadata = $metadata;
            $changed = true;
            $content->pending_candidate = null;
        }
        $content->save();

        return $changed;
    }
}
