<?php

namespace App\Services\Reporting;

use InvalidArgumentException;

/** Optional assistant adapter: seven read-only tools; no model integration or generated SQL. */
class CorpusReportingTools
{
    public function __construct(private CampaignReportingService $reports) {}

    public function catalog(): array
    {
        return ['get_campaign_status' => ['campaign_id'], 'get_campaign_statistics' => ['campaign_id', 'snapshot'],
            'get_quality_summary' => [], 'get_discovery_coverage' => ['campaign_id'], 'get_failed_import_summary' => ['campaign_id', 'limit'],
            'compare_campaigns' => ['campaign_ids'], 'get_corpus_summary' => []];
    }

    public function call(string $tool, array $arguments = []): array
    {
        $catalog = $this->catalog();
        if (! array_key_exists($tool, $catalog) || array_diff(array_keys($arguments), $catalog[$tool]) !== []) {
            throw new InvalidArgumentException('Only approved read-only tools and their declared arguments are permitted.');
        }
        $id = $arguments['campaign_id'] ?? null;
        if ($id !== null && (! is_string($id) || ! preg_match('/^[a-z0-9-]{1,60}$/D', $id))) {
            throw new InvalidArgumentException('Invalid campaign ID.');
        }
        if (in_array($tool, ['get_campaign_status', 'get_campaign_statistics'], true) && $id === null) {
            $id = $this->reports->campaigns()['campaigns'][0]['campaign_id'] ?? null;
            if ($id === null) {
                return ['observed_at' => now()->toAtomString(), 'campaign_id' => null, 'metric_status' => 'unavailable'];
            }
        }
        if (isset($arguments['limit']) && (! is_numeric($arguments['limit']) || (string) (int) $arguments['limit'] !== (string) $arguments['limit'])) {
            throw new InvalidArgumentException('Limit must be an integer.');
        }
        if (isset($arguments['campaign_ids']) && ! is_array($arguments['campaign_ids'])) {
            throw new InvalidArgumentException('Campaign IDs must be a bounded array.');
        }
        if (isset($arguments['snapshot']) && ! in_array($arguments['snapshot'], ['current', 'final'], true)) {
            throw new InvalidArgumentException('Snapshot must be current or final.');
        }

        return match ($tool) {
            'get_campaign_status' => $this->reports->campaign($id),
            'get_campaign_statistics' => ($arguments['snapshot'] ?? 'current') === 'final' ? $this->reports->finalReport($id) : $this->reports->campaign($id),
            'get_quality_summary' => $this->reports->quality(),
            'get_discovery_coverage' => $this->reports->discovery($id),
            'get_failed_import_summary' => $this->reports->failures($id, (int) ($arguments['limit'] ?? 20)),
            'compare_campaigns' => $this->reports->compare($arguments['campaign_ids'] ?? []),
            'get_corpus_summary' => $this->reports->corpus(),
        };
    }

    public function answer(string $question, ?string $id = null): array
    {
        if (mb_strlen($question) > 500 || trim($question) === '') {
            throw new InvalidArgumentException('Question must contain1..500characters.');
        }
        $q = mb_strtolower($question);
        $args = $id === null ? [] : ['campaign_id' => $id];
        if (str_contains($q, 'taqqosla')) {
            $ids = array_column(array_slice($this->reports->campaigns()['campaigns'], 0, 2), 'campaign_id');
            $evidence = $this->call('compare_campaigns', ['campaign_ids' => $ids]);
            $answer = implode('; ', array_map(fn ($report) => $report['campaign_id'].': '.$report['new_canonical'].'/'.$report['target_new_canonical'].' yangi canonical, '.$report['status'], $evidence['campaigns']));
            $tool = 'compare_campaigns';
        } elseif (str_contains($q, 'needs_review') || str_contains($q, 'sifat') || str_contains($q, 'pdf')) {
            $tool = 'get_quality_summary';
            $evidence = $this->call($tool);
            $answer = 'Saqlangan contentlar: verified '.($evidence['content_quality']['verified'] ?? 0).', needs_review '.($evidence['content_quality']['needs_review'] ?? 0).'. PDF/OCR sabab kodlari dalilda berilgan. Verified inson yoki yuridik sertifikati emas.';
        } elseif (str_contains($q, 'xato') || str_contains($q, 'o‘qiy olmadi') || str_contains($q, "o'qiy olmadi")) {
            $tool = 'get_failed_import_summary';
            $evidence = $this->call($tool, $args);
            $answer = 'Review yoki failed itemlar: '.$evidence['total_review_or_failed_items'].'. Quyida cheklangan diagnostika bor; review texnik failure degani emas.';
        } elseif (str_contains($q, 'campaign') || str_contains($q, 'budget') || str_contains($q, 'kampaniya')) {
            $tool = 'get_campaign_status';
            $evidence = $this->call($tool, $args);
            $answer = isset($evidence['status']) ? $evidence['campaign_id'].': '.$evidence['status'].', yangi canonical '.$evidence['new_canonical'].'/'.$evidence['target_new_canonical'].', qolgan HTTP budget '.$evidence['http']['remaining'].'.' : 'Kampaniya ma’lumoti mavjud emas.';
        } elseif (str_contains($q, 'bugun') || str_contains($q, '24 soat') || str_contains($q, 'jami') || str_contains($q, 'nechta')) {
            $tool = 'get_corpus_summary';
            $evidence = $this->call($tool);
            $answer = 'Jami canonical hujjatlar: '.$evidence['canonical_documents'].'. Bugun (Asia/Tashkent): '.$evidence['today']['new_canonical_documents'].'. Oxirgi24soat: '.$evidence['last_24_hours']['new_canonical_documents'].', devor soatiga '.$evidence['last_24_hours']['new_documents_per_wall_hour'].'. Bu barcha hujjat turlari; active processing tezligi emas.';
        } else {
            throw new InvalidArgumentException('Bu savol uchun tasdiqlangan reporting intent yo‘q. Tool katalogidan foydalaning.');
        }

        return ['language' => 'uz', 'mode' => 'deterministic_read_only_adapter; no LLM invoked', 'answer' => $answer, 'tool' => $tool, 'evidence' => $evidence];
    }
}
