<?php

namespace App\Scraping\LexUz;

use App\Scraping\Contracts\DocumentScraper;
use App\Services\Documents\DocumentValidator;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class LexUzScraper implements DocumentScraper
{
    public function __construct(private LexHttpClient $http, private LexUzParser $parser, private DocumentValidator $validator) {}

    public function fetch(string $externalId, ?string $listingStatus = null): array
    {
        if (! preg_match('/^-?\d+$/D', $externalId)) {
            throw new SourceReviewException('Invalid Lex.uz source identifier.');
        }
        // Do not carry viewer language/session preferences from an unrelated document group.
        $this->http->resetSession();
        $pending = [$externalId => 'https://lex.uz/uz/docs/'.$externalId];
        $pages = [];
        while ($pending !== []) {
            $id = (string) array_key_first($pending);
            $url = $pending[$id];
            unset($pending[$id]);
            if (isset($pages[$id])) {
                continue;
            }
            if (count($pages) >= config('scraping.max_identity_nodes')) {
                throw new SourceReviewException('Source identity graph exceeds the bounded variant budget.');
            }
            $html = $this->http->get($url)->body();
            $cardUrl = $this->parser->cardUrl($html);
            $card = $this->parser->card($this->http->get($cardUrl)->body());
            $links = $this->parser->links($html, $id, $card);
            // Parsed source evidence survives an identity rejection; no cookies/hidden state/body text.
            Log::channel('scraping')->info('lex_source_observation', ['source_id' => $id, 'source_url' => $url, 'card_url' => $cardUrl,
                'card' => $card, 'links' => $links, 'html_sha256' => hash('sha256', $html)]);
            $declared = $this->parser->language($card);
            foreach ($links as $requested => $link) {
                if ((string) $link['id'] === $id && $requested !== $declared) {
                    // HTTP200 fallback is not proof that the requested translation exists.
                    throw new SourceReviewException('Source language fallback: requested '.$requested.'; card '.$declared.'; raw label '.($card['Язык акта'] ?? ''));
                }
            }
            $pages[$id] = ['url' => $url, 'html' => $html, 'card_url' => $cardUrl, 'card' => $card, 'links' => $links];
            foreach ($links as $link) {
                if (! isset($pages[$link['id']])) {
                    $pending[$link['id']] = $link['url'];
                }
            }
        }
        // Stable preferred metadata language does not depend on the discovery entrypoint.
        $primary = $externalId;
        foreach ($pages as $id => $page) {
            if ($this->parser->language($page['card']) === 'uz-Latn') {
                $primary = (string) $id;
                break;
            }
        }
        $preferred = $pages[$primary];
        $card = $preferred['card'];
        $evidence = $this->parser->status($card, $primary === $externalId ? $listingStatus : null);
        $identity = [];
        $contents = [];
        $quality = [];
        $bodyDates = [];
        $retryable = false;
        $technicalException = null;
        $primaryFormat = 'html';
        foreach ($pages as $id => &$page) {
            $state = $this->parser->status($page['card'], (string) $id === $externalId ? $listingStatus : null);
            if ($state['state'] !== $evidence['state'] || (isset($state['listing_state']) && $state['listing_state'] !== $state['state'])) {
                $evidence['state'] = 'unknown';
            }
            $evidence['effective_at_candidates'] = [...$evidence['effective_at_candidates'], ...$state['effective_at_candidates']];
            $language = $this->parser->language($page['card']);
            try {
                $parsed = $this->parser->content($page['html'], $page['card']);
            } catch (Throwable $e) {
                if (! $e instanceof SourceReviewException) {
                    $technicalException ??= $e;
                }
                $retryable = $retryable || ($e instanceof LexRequestException && ($e->status === 0 || $e->status === 429 || $e->status >= 500));
                $parsed = ['content' => '', 'language' => $language, 'metadata_only' => false, 'quality' => ['format' => $this->parser->pdfUrl($page['html']) ? 'pdf' : 'html', 'complete' => false, 'structure_verified' => false, 'issues' => ['extraction_failed'], 'error_class' => $e::class, 'error' => mb_substr($e->getMessage(), 0, 240)]];
            }
            $language = $parsed['language'];
            $metadataOnly = $parsed['metadata_only'];
            $availability = $this->parser->availability($page['html'], $page['card'], $parsed);
            if ($language !== $this->parser->language($page['card'])) {
                $availability['metadata']['physical_language'] = $language;
            }
            $identity[] = ['external_id' => (string) $id, 'language' => $language, 'source_url' => $page['url'],
                'availability' => $metadataOnly ? 'metadata_only' : 'full_text',
                'availability_evidence' => $availability,
                'links' => array_values(array_map(fn ($link) => (string) $link['id'], $page['links'])),
                'requisites' => $this->parser->requisites($page['card'])];
            $quality[$language] = $parsed['quality'];
            if ((string) $id === $externalId) {
                $primaryFormat = $parsed['quality']['format'];
            }
            if (! $metadataOnly) {
                $contents[$language] = ['title' => $page['card']['Наименование акта'], 'content' => $parsed['content'], 'quality' => $parsed['quality'],
                    'provenance' => ['source_url' => $page['url'], 'source_html_sha256' => hash('sha256', $page['html']),
                        'parser' => LexUzParser::class, 'parser_sha256' => hash_file('sha256', __DIR__.'/LexUzParser.php'),
                        'pdf_extractor_sha256' => hash_file('sha256', __DIR__.'/PdfTextExtractor.php')]];
                $bodyDates = [...$bodyDates, ...$this->parser->wholeEffectiveDates($parsed['content'])];
            }
            // Release large source DOM strings after this variant is parsed.
            unset($page['html']);
        }
        unset($page);
        $evidence['effective_at_candidates'] = array_values(array_unique($evidence['effective_at_candidates'], SORT_REGULAR));
        $this->validator->identity($identity, $externalId);
        $bodyDates = array_values(array_unique($bodyDates));
        $requisites = $this->parser->requisites($card);

        $metadataQuality = array_map(fn ($q) => array_diff_key($q, ['timings' => true]), $quality);

        return ['entry_id' => $externalId, 'title' => $card['Наименование акта'], 'source_url' => $preferred['url'],
            'document_type' => $card['Форма акта'] ?? null, 'document_number' => $card['accepting_bodies'][0][5] ?: null,
            'issued_at' => $this->parser->date($card['accepting_bodies'][0][4]), 'published_at' => $requisites['publication_date'],
            'status_evidence' => $evidence, 'text_effective_at' => count($bodyDates) === 1 ? $bodyDates[0] : null,
            'identity' => $identity, 'contents' => $contents,
            'metadata' => ['lex' => ['source_card' => $card, 'metadata_url' => $preferred['card_url'], 'body_effective_dates' => $bodyDates, 'quality' => $metadataQuality]],
            ...($technicalException === null ? [] : ['technical_exception' => $technicalException]),
            'diagnostics' => ['primary_format' => $primaryFormat, 'quality' => $quality, 'retryable_extraction' => $retryable]];
    }

    public function searchUrl(array $segment): string
    {
        if (! in_array($segment['section'] ?? null, ['nat', 'loc', 'int', 'tech', 'court', 'oliy', 'official'], true)) {
            throw new SourceReviewException('Unsupported source search section.');
        }
        $parameters = array_intersect_key($segment, array_flip(['status', 'from', 'to', 'from_eff', 'to_eff', 'court_form', 'pub_date']));
        if (isset($segment['lang'])) {
            throw new SourceReviewException('Language-restricted discovery is forbidden.');
        }

        return 'https://lex.uz/uz/search/'.$segment['section'].'?'.http_build_query($parameters);
    }

    public function listing(string $html): array
    {
        $crawler = new Crawler($html);
        $text = $crawler->text('', true);
        $total = null;
        if (preg_match('/([\d\s\x{00a0}]+)\s+hujjat topildi/u', $text, $match)) {
            $total = (int) preg_replace('/\D/u', '', $match[1]);
        } elseif (str_contains($text, 'Sizning so‘rovingiz bo‘yicha hujjat topilmadi!')) {
            $total = 0;
        }
        if ($total === null) {
            throw new SourceReviewException('Unknown listing structure; not treated as an empty result.');
        }
        $rows = [];
        foreach ($crawler->filter('a.lx_link[href]') as $link) {
            if (! preg_match('~^/uz/docs/(-?\d+)$~D', $link->getAttribute('href'), $match)) {
                continue;
            }
            $row = (new Crawler($link))->closest('tr');
            $status = $row->filter('[class*="status_code_"]')->count() ? $row->filter('[class*="status_code_"]')->first()->attr('class') : '';
            preg_match('/status_code_([yrn])/i', $status, $statusMatch);
            $rows[] = ['id' => $match[1], 'url' => $this->http->absolute($link->getAttribute('href')), 'title' => trim($link->textContent), 'listing_status' => isset($statusMatch[1]) ? strtoupper($statusMatch[1]) : null];
        }
        if ($total > 0 && $rows === []) {
            throw new SourceReviewException('Positive source total with missing listing rows.');
        }
        $next = null;
        $button = $crawler->filter('#ucFoundActsControl_LinkButton1[href]');
        if ($button->count() && preg_match("/__doPostBack\\('([^']+)'(?:,\\s*'([^']*)')?/", $button->attr('href'), $match)) {
            $next = ['__EVENTTARGET' => $match[1], '__EVENTARGUMENT' => $match[2] ?? ''];
        } elseif ($button->count()) {
            throw new SourceReviewException('Source pagination target is unrecognized.');
        }
        $hidden = [];
        foreach ($crawler->filter('#Form1 input[type="hidden"][name]') as $input) {
            $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
        }
        if ($next !== null && (! isset($hidden['__VIEWSTATE']) || ! isset($hidden['__VIEWSTATEGENERATOR']))) {
            throw new SourceReviewException('Pagination hidden state is absent.');
        }

        return ['total' => $total, 'rows' => $rows, 'next' => $next, 'hidden' => $hidden, 'fingerprint' => hash('sha256', json_encode(array_column($rows, 'id')))];
    }

    public function discover(array $segment, callable $onPage, int $pageBudget): array
    {
        $url = $this->searchUrl($segment);
        $seen = [];
        $restarts = 0;
        restart:
        $this->http->resetSession();
        $html = $this->http->get($url)->body();
        $total = null;
        $fingerprints = [];
        $prefix = hash('sha256', '');
        for ($page = 1; $page <= $pageBudget; $page++) {
            try {
                $listing = $this->listing($html);
                if ($total !== null && $total !== $listing['total']) {
                    throw new SourceReviewException('Source total drifted within a segment.');
                }
                $total = $listing['total'];
                if (isset($fingerprints[$listing['fingerprint']])) {
                    throw new SourceReviewException('Pagination cycled; no page may be skipped.');
                }
            } catch (RuntimeException $e) {
                if (++$restarts <= 1) {
                    // No durable checkpoint has advanced for this invalid response.
                    $seen = [];
                    goto restart;
                }
                throw $e;
            }
            $fingerprints[$listing['fingerprint']] = true;
            $prefix = hash('sha256', $prefix.$listing['fingerprint']);
            foreach ($listing['rows'] as $row) {
                $seen[$row['id']] = true;
            }
            $continue = $onPage(['page' => $page, 'total' => $total, 'rows' => $listing['rows'], 'fingerprint' => $listing['fingerprint'], 'prefix_fingerprint' => $prefix, 'last_page' => $listing['next'] === null]);
            if ($listing['next'] === null) {
                if (count($seen) !== $total) {
                    throw new SourceReviewException('Segment end did not reconcile with the source total.');
                }

                return ['complete' => true, 'total' => $total, 'unique_ids' => count($seen), 'pages' => $page, 'restarts' => $restarts];
            }
            if ($continue === false || $page === $pageBudget) {
                return ['complete' => false, 'total' => $total, 'unique_ids' => count($seen), 'pages' => $page, 'restarts' => $restarts, 'reason' => 'bounded_budget'];
            }
            $html = $this->http->post($url, array_replace($listing['hidden'], $listing['next']))->body();
        }
        throw new SourceReviewException('Discovery page budget must be positive.');
    }
}
