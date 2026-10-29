<?php

namespace App\Services\Documents;

use App\Enums\DocumentStatus;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

class DocumentValidator
{
    /** Validate a closed, connected graph of reciprocal source links. No title/hash matching. */
    public function identity(array $nodes, string $entryId): array
    {
        $indexed = [];
        $languages = [];
        $consensus = [];
        foreach ($nodes as $node) {
            $id = $node['external_id'] ?? null;
            if (! is_string($id) || $id === '' || strlen($id) > 255 || isset($indexed[$id])) {
                $this->reject('identity', 'Missing, oversized or duplicate source identifier.');
            }
            $language = $node['language'] ?? '';
            if (! is_string($language) || ! preg_match('/^[a-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/D', $language) || strlen($language) > 35) {
                $this->reject('identity', 'Invalid language tag.');
            }
            if (! in_array($node['availability'] ?? null, ['full_text', 'metadata_only'], true)) {
                $this->reject('identity', 'Source language availability must be explicit.');
            }
            if (isset($node['availability_evidence'])) {
                $observation = $node['availability_evidence'];
                if (! in_array($observation['status'] ?? null, ['available', 'not_available', 'unknown'], true)
                    || ! is_string($observation['type'] ?? null) || ! is_array($observation['metadata'] ?? null)
                    || ($node['availability'] === 'full_text' && $observation['status'] !== 'available')
                    || ($node['availability'] === 'metadata_only' && $observation['status'] === 'available')) {
                    $this->reject('identity', 'Contradictory or malformed language availability evidence.');
                }
            }
            if (isset($languages[$language])) {
                $this->reject('identity', 'Ambiguous multiple identifiers for one language.');
            }
            $languages[$language] = true;
            $requisites = $node['requisites'] ?? [];
            foreach (['type', 'form', 'bodies'] as $key) {
                if (empty($requisites[$key])) {
                    $this->reject('identity', 'Missing source requisites: '.$key);
                }
            }
            if (! is_string($requisites['type']) || trim($requisites['type']) === '' || ! is_string($requisites['form']) || trim($requisites['form']) === '' || ! is_array($requisites['bodies']) || ! array_is_list($requisites['bodies'])) {
                $this->reject('identity', 'Malformed source requisites.');
            }
            foreach ($requisites['bodies'] as $body) {
                if (! is_array($body) || ! is_string($body['issuer'] ?? null) || trim($body['issuer']) === '' || ! is_string($body['date'] ?? null)) {
                    $this->reject('identity', 'Adopting body and date must be explicit.');
                }
                $this->date($body['date']);
            }
            foreach ($requisites as $field => $value) {
                if ($value === null || $value === '' || $value === []) {
                    continue;
                }
                if (isset($consensus[$field]) && ! $this->equivalent($consensus[$field], $value)) {
                    $this->reject('identity', 'Conflicting component requisites: '.$field);
                }
                $consensus[$field] = $value;
            }
            if (! is_array($node['links'] ?? null)) {
                $this->reject('identity', 'Source links must be explicit.');
            }
            $indexed[$id] = $node;
        }
        if (! isset($indexed[$entryId])) {
            $this->reject('identity', 'Entrypoint is outside the verified graph.');
        }
        foreach ($indexed as $id => $node) {
            foreach ($node['links'] as $targetId) {
                if (! is_string($targetId) || ! isset($indexed[$targetId]) || ! in_array((string) $id, $indexed[$targetId]['links'], true)) {
                    $this->reject('identity', 'Missing node or nonreciprocal source link.');
                }
                foreach (array_unique([...array_keys($node['requisites']), ...array_keys($indexed[$targetId]['requisites'])]) as $field) {
                    $a = $node['requisites'][$field] ?? null;
                    $b = $indexed[$targetId]['requisites'][$field] ?? null;
                    // Missing optional publication/registration evidence is not a contradiction.
                    if ($a !== null && $a !== '' && $a !== [] && $b !== null && $b !== '' && $b !== [] && ! $this->equivalent($a, $b)) {
                        $this->reject('identity', 'Conflicting source requisites: '.$field);
                    }
                }
            }
        }
        $visited = [];
        $queue = [$entryId];
        while ($queue !== []) {
            $id = array_pop($queue);
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            array_push($queue, ...$indexed[$id]['links']);
        }
        if (count($visited) !== count($indexed)) {
            $this->reject('identity', 'Disconnected identity component.');
        }

        return $indexed;
    }

    /** Source adapter supplies normalized metadata evidence; body dates can only generate warnings. */
    public function status(array $evidence, DateTimeInterface $at, ?string $textEffectiveAt = null): array
    {
        $state = $evidence['state'] ?? null;
        if (($evidence['authority'] ?? null) !== 'source_metadata' || ! in_array($state, ['active', 'revoked', 'not_in_force'], true)) {
            $this->reject('status', 'Unknown or absent source metadata; review required.');
        }
        if (isset($evidence['listing_state']) && $evidence['listing_state'] !== $state) {
            $this->reject('status', 'Source listing and card contradict one another.');
        }
        $effective = $this->date($evidence['effective_at'] ?? null);
        $dates = $evidence['effective_at_candidates'] ?? [$effective];
        if (! is_array($dates) || count(array_unique($dates, SORT_REGULAR)) !== 1 || ($dates[0] ?? null) !== $effective) {
            $this->reject('status', 'Internally contradictory effective date metadata.');
        }
        $today = (new DateTimeImmutable($at->format(DateTimeInterface::ATOM)))->setTimezone(new \DateTimeZone('Asia/Tashkent'))->format('Y-m-d');
        if ($state !== 'revoked' && $effective === null) {
            $this->reject('status', 'Source effective date is missing.');
        }
        if ($state === 'not_in_force' && $effective > $today) {
            $this->reject('status', 'Not-in-force metadata cannot prove pending status.');
        }
        $status = match ($state) {
            'revoked', 'not_in_force' => DocumentStatus::Inactive,
            default => $effective > $today ? DocumentStatus::Pending : DocumentStatus::Active,
        };
        $warnings = [];
        if ($textEffectiveAt !== null && $this->date($textEffectiveAt) !== $effective) {
            $warnings[] = ['code' => 'effective_date_mismatch', 'source_effective_at' => $effective, 'text_effective_at' => $textEffectiveAt];
        }

        return ['status' => $status, 'effective_at' => $effective, 'warnings' => $warnings];
    }

    public function date(?string $date): ?string
    {
        if ($date === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date) {
            $this->reject('date', 'Invalid ISO source date.');
        }

        return $date;
    }

    /** JSONB reorders object keys; compare meaning with strict scalar types and ordered lists. */
    public function equivalent(mixed $a, mixed $b): bool
    {
        $normalize = function (mixed $value) use (&$normalize): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($normalize, $value);
        };

        return $normalize($a) === $normalize($b);
    }

    /** Content rejection is local to this language. Infrastructure exceptions still roll back the transaction. */
    public function content(?array $candidate, ?string $previous): array
    {
        if ($candidate === null) {
            return ['errors' => ['missing_required_language']];
        }
        $errors = [];
        $content = $candidate['content'] ?? null;
        if (! is_string($content) || trim($content) === '' || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0") || str_contains($content, "\u{FFFD}")) {
            $errors[] = 'invalid_text';
        }
        if (! is_string($candidate['title'] ?? null) || trim($candidate['title']) === '') {
            $errors[] = 'missing_language_title';
        }
        $quality = $candidate['quality'] ?? [];
        if (($quality['complete'] ?? false) !== true || ($quality['structure_verified'] ?? false) !== true || ($quality['issues'] ?? null) !== []) {
            $errors[] = 'quality_review';
        }
        $format = $quality['format'] ?? null;
        if (! in_array($format, ['html', 'pdf', 'ocr'], true)) {
            $errors[] = 'unknown_format';
        }
        if (in_array($format, ['pdf', 'ocr'], true) && (! is_int($quality['pages_expected'] ?? null) || $quality['pages_expected'] < 1 || $quality['pages_expected'] !== ($quality['pages_extracted'] ?? null))) {
            $errors[] = 'incomplete_pdf_pages';
        }
        if ($format === 'ocr' && ($quality['document_certified'] ?? false) !== true) {
            $errors[] = 'uncertified_ocr';
        }
        $normalized = is_string($content) ? trim(str_replace(["\r\n", "\r"], "\n", $content))."\n" : '';
        // Conservative interim guard, not a production OCR/accuracy acceptance threshold.
        if ($previous !== null && mb_strlen($normalized) < mb_strlen($previous) * 0.7) {
            $errors[] = 'suspected_truncation';
        }

        return ['errors' => $errors, 'content' => $normalized, 'hash' => hash('sha256', $normalized)];
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
