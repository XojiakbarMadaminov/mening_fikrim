<?php

namespace App\Services\Documents;

use DateTimeImmutable;
use DateTimeInterface;

/** Source-neutral observations in existing document JSONB, never empty content rows. */
class LanguageAvailability
{
    public function __construct(private DocumentValidator $validator) {}

    public function comparable(array $observations): array
    {
        foreach ($observations as &$entry) {
            unset($entry['checked_at'], $entry['evidence']['html_sha256']);
            if (isset($entry['previous_observation'])) {
                $entry['previous_observation'] = $this->comparable([$entry['previous_observation']])[0];
            }
        }

        return $observations;
    }

    public function observe(array $identity, DateTimeInterface $at, array $previous = []): array
    {
        $result = $previous;
        foreach ($identity as $node) {
            $language = $node['language'];
            $observation = $node['availability_evidence'] ?? null;
            // Legacy metadata_only means non-content, but is not by itself explicit absence proof.
            $status = $node['availability'] === 'full_text' ? 'available' : 'unknown';
            if ($observation !== null && in_array($observation['status'] ?? null, ['available', 'not_available', 'unknown'], true)) {
                $status = $observation['status'];
            }
            $incoming = ['status' => $status, 'evidence_type' => $observation['type'] ?? 'identity_representation',
                'evidence' => $observation['metadata'] ?? ['representation' => $node['availability']],
                'source_id' => $node['external_id'], 'source_url' => $node['source_url'] ?? null,
                'checked_at' => $at->format(DateTimeInterface::ATOM)];
            $old = $result[$language] ?? null;
            if ($old && isset($old['checked_at']) && new DateTimeImmutable($old['checked_at']) > $at) {
                continue;
            }
            if ($old && ! $this->validator->equivalent($this->comparable([array_diff_key($old, ['previous_observation' => true])]), $this->comparable([$incoming]))) {
                // Bounded history: retain the preceding evidence when the source changes.
                $incoming['previous_observation'] = array_diff_key($old, ['previous_observation' => true]);
            } elseif (isset($old['previous_observation'])) {
                $incoming['previous_observation'] = $old['previous_observation'];
            }
            $result[$language] = $incoming;
        }
        foreach (['uz-Cyrl', 'uz-Latn', 'ru', 'en'] as $language) {
            $result[$language] ??= ['status' => 'unknown', 'evidence_type' => 'no_conclusive_source_evidence', 'evidence' => [], 'checked_at' => $at->format(DateTimeInterface::ATOM)];
        }
        ksort($result);

        return $result;
    }
}
