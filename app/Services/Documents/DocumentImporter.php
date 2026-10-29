<?php

namespace App\Services\Documents;

use App\Models\Document;
use App\Models\DocumentIdentifier;
use App\Models\DocumentSource;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentImporter
{
    public function __construct(private DocumentValidator $validator, private ContentQuality $quality, private LanguageAvailability $availability) {}

    /** See docs/IMPORT_CONTRACT.md. No fetching, selectors or source-specific ID rules here. */
    public function import(int $sourceId, array $input, DateTimeInterface $checkedAt): array
    {
        $identity = $this->validator->identity($input['identity'] ?? [], $input['entry_id'] ?? '');
        $status = $this->validator->status($input['status_evidence'] ?? [], $checkedAt, $input['text_effective_at'] ?? null);
        if (! is_string($input['title'] ?? null) || trim($input['title']) === '') {
            throw ValidationException::withMessages(['title' => 'Document title is required.']);
        }
        $dates = [];
        foreach (['issued_at', 'published_at'] as $field) {
            $dates[$field] = $this->validator->date($input[$field] ?? null);
        }

        return DB::transaction(function () use ($sourceId, $input, $checkedAt, $identity, $status, $dates) {
            // Existing source row makes even the first import race lockable. One worker per source is the conservative baseline.
            $source = DocumentSource::query()->lockForUpdate()->findOrFail($sourceId);
            if (! $source->is_active) {
                throw ValidationException::withMessages(['source' => 'Source is disabled.']);
            }
            $ids = array_map('strval', array_keys($identity));
            $mappings = DocumentIdentifier::query()->where('source_id', $sourceId)->whereIn('external_id', $ids)->get();
            $documentIds = $mappings->pluck('document_id')->unique();
            // Canonical anchors also protect against an out-of-protocol writer without identifier rows.
            $anchors = Document::query()->where('source_id', $sourceId)->whereIn('external_id', $ids)->pluck('id');
            $documentIds = $documentIds->merge($anchors)->unique();
            if ($documentIds->count() > 1) {
                throw ValidationException::withMessages(['identity' => 'Identifiers already belong to different documents; manual review required.']);
            }
            $document = $documentIds->isEmpty() ? new Document(['source_id' => $sourceId]) : Document::query()->lockForUpdate()->findOrFail($documentIds->first());
            $created = ! $document->exists;
            if (! $created && $document->last_checked_at !== null && $document->last_checked_at > $checkedAt) {
                throw ValidationException::withMessages(['verification' => 'Stale verification cannot replace a newer successful check.']);
            }
            if (! $created && $document->identifiers()->whereNotIn('external_id', $ids)->exists()) {
                throw ValidationException::withMessages(['identity' => 'Incoming graph omits previously verified identifiers.']);
            }
            foreach ($mappings as $mapping) {
                $node = $identity[$mapping->external_id];
                if ($mapping->language !== null && $mapping->language !== $node['language']) {
                    throw ValidationException::withMessages(['identity' => 'Persisted identifier language conflicts with incoming graph.']);
                }
                $oldRequisites = $mapping->metadata['requisites'] ?? [];
                foreach ($oldRequisites as $field => $value) {
                    $newValue = $node['requisites'][$field] ?? null;
                    if ($value !== null && $value !== '' && $value !== [] && $newValue !== null && $newValue !== '' && $newValue !== [] && ! $this->validator->equivalent($value, $newValue)) {
                        throw ValidationException::withMessages(['identity' => 'Persisted source requisites conflict.']);
                    }
                }
            }
            $outcomes = [];
            $storable = [];
            $requiredLanguages = [];
            $oldContents = $document->exists ? $document->contents()->get()->keyBy('language') : collect();
            foreach ($identity as $node) {
                $language = $node['language'];
                if ($node['availability'] === 'metadata_only') {
                    $outcomes[$language] = ['state' => 'metadata_only', 'errors' => [],
                        'availability_status' => $node['availability_evidence']['status'] ?? 'unknown',
                        'availability_evidence_type' => $node['availability_evidence']['type'] ?? 'legacy_metadata_only', 'extraction_outcome' => 'not_attempted'];

                    continue;
                }
                $requiredLanguages[] = $language;
                $result = $this->quality->evaluate($input['contents'][$language] ?? null, $oldContents->get($language), $checkedAt);
                $outcomes[$language] = ['state' => $result['quality_status'] === 'verified' ? 'valid' : 'review', 'errors' => $result['errors'],
                    'availability_status' => 'available', 'extraction_outcome' => $result['usable'] ? 'succeeded' : 'failed',
                    'quality_status' => $result['quality_status'], 'quality_issues' => $result['quality_issues'],
                    'persistence' => $result['persistence'], 'extracted_hash' => $result['hash'] ?? null,
                    'stored_quality_status' => $result['persistence'] === 'stored' ? $result['quality_status'] : $oldContents->get($language)?->quality_status];
                if ($result['usable'] && $result['persistence'] !== 'stale_ignored') {
                    $storable[$language] = ['assessment' => $result, 'source_url' => $node['source_url'] ?? $input['source_url'] ?? null];
                }
            }
            sort($requiredLanguages, SORT_STRING);
            $complete = $requiredLanguages !== [] && count(array_filter($outcomes, fn ($outcome) => $outcome['state'] === 'valid')) === count($requiredLanguages);
            // Metadata-only pages may bind to an existing identity but cannot create a content-less document.
            if ($created && $storable === []) {
                return ['document_id' => null, 'action' => 'rejected', 'import_outcome' => 'extraction_failed', 'quality_status' => 'extraction_failed',
                    'persisted_content_count' => 0, 'persistence_complete' => false, 'complete' => false, 'languages' => $outcomes, 'warnings' => $status['warnings']];
            }
            if ($created) {
                sort($ids, SORT_STRING);
                $document->external_id = $ids[0];
            }
            $previousMeta = $document->metadata ?? [];
            $metadata = array_replace($previousMeta, $input['metadata'] ?? []);
            $metadata['language_availability'] = $this->availability->observe($identity, $checkedAt, $previousMeta['language_availability'] ?? []);
            $metadata['validation'] = ['complete' => $complete, 'required_languages' => $requiredLanguages, 'languages' => $outcomes, 'warnings' => $status['warnings']];
            // Compare the same JSON representation Eloquent persists (e.g. 0.0 becomes 0).
            // Identity/requisite equivalence above remains strict and unchanged.
            $metadata = json_decode(json_encode($metadata, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
            $document->fill([
                'title' => $input['title'],
                'document_type' => $input['document_type'] ?? null,
                'document_number' => $input['document_number'] ?? null,
                'source_url' => $input['source_url'] ?? null,
                'status' => $status['status'], 'effective_at' => $status['effective_at'],
                'metadata' => $metadata,
                ...$dates,
            ]);
            $changed = $document->isDirty(array_diff(array_keys($document->getAttributes()), ['metadata', 'created_at', 'updated_at', 'last_checked_at']))
                || ! $this->validator->equivalent(
                    array_replace($previousMeta, ['language_availability' => $this->availability->comparable($previousMeta['language_availability'] ?? [])]),
                    array_replace($metadata, ['language_availability' => $this->availability->comparable($metadata['language_availability'] ?? [])])
                );
            if ($complete) {
                $document->last_checked_at = $checkedAt;
            }
            $document->save();
            foreach ($identity as $id => $node) {
                $identifier = $document->identifiers()->firstOrNew(['source_id' => $sourceId, 'external_id' => (string) $id]);
                // Keep proof fields when a translated card omits an optional value on a later check.
                $oldProof = $identifier->metadata ?? [];
                $links = array_values(array_unique($node['links']));
                sort($links, SORT_STRING);
                $requisites = $node['requisites'];
                foreach ($oldProof['requisites'] ?? [] as $field => $value) {
                    if (($requisites[$field] ?? null) === null || $requisites[$field] === '') {
                        $requisites[$field] = $value;
                    }
                }
                $proof = array_replace($oldProof, ['availability' => $node['availability'], 'links' => $links, 'requisites' => $requisites]);
                $identifier->fill(['language' => $node['language'], 'source_url' => $node['source_url'] ?? null, 'metadata' => $proof]);
                $changed = $changed || $identifier->isDirty(['source_id', 'external_id', 'language', 'source_url']) || ! $this->validator->equivalent($oldProof, $proof);
                $identifier->save();
            }
            foreach ($storable as $language => $result) {
                $content = $oldContents->get($language) ?? $document->contents()->make(['language' => $language]);
                $contentChanged = $this->quality->persist($content, $input['contents'][$language], $result['assessment'], $input['title'], $result['source_url'], $checkedAt);
                $changed = $changed || $contentChanged;
            }
            $action = $created ? 'created' : ($changed ? 'updated' : 'unchanged');

            $hasExtracted = count(array_filter($outcomes, fn ($outcome) => in_array($outcome['quality_status'] ?? null, ['verified', 'needs_review'], true))) > 0;

            return ['document_id' => $document->id, 'action' => $action, 'import_outcome' => $storable === [] ? ($hasExtracted ? 'not_persisted' : 'extraction_failed') : ($action === 'unchanged' ? 'unchanged' : 'imported'),
                'quality_status' => $complete ? 'verified' : 'needs_review',
                'persisted_content_count' => count($storable), 'persistence_complete' => $requiredLanguages !== [] && count($storable) === count($requiredLanguages),
                'complete' => $complete, 'languages' => $outcomes, 'warnings' => $status['warnings']];
        }, 3);
    }
}
