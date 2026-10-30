<?php

namespace App\Scraping\LexUz;

use Symfony\Component\DomCrawler\Crawler;

class LexUzParser
{
    public function __construct(private LexHttpClient $http, private HtmlMarkdownParser $html, private PdfTextExtractor $pdf) {}

    public function cardUrl(string $html): string
    {
        if (! preg_match("/showIframeCard\\(['\"]([^'\"]*card1\/[^'\"]+)['\"]/u", $html, $match)) {
            throw new SourceReviewException('Source page does not expose a metadata card URL.');
        }

        return $this->http->absolute($match[1]);
    }

    public function pdfUrl(string $html): ?string
    {
        return preg_match('/PDFObject\.embed\(\s*[\'"]([^\'"]+)[\'"]/u', $html, $match) ? $this->http->absolute($match[1]) : null;
    }

    public function card(string $html): array
    {
        $crawler = new Crawler($html);
        $fields = [];
        foreach ($crawler->filter('td.lbl') as $label) {
            $value = $label->nextSibling;
            while ($value !== null && ! ($value instanceof \DOMElement && $value->tagName === 'td')) {
                $value = $value->nextSibling;
            }
            if ($value !== null) {
                $key = rtrim($this->compact($label->textContent), ':');
                $text = $this->compact($value->textContent);
                if (isset($fields[$key]) && $fields[$key] !== $text) {
                    throw new SourceReviewException('Contradictory duplicate source metadata field.');
                }
                $fields[$key] = $text;
            }
        }
        $fields['accepting_bodies'] = [];
        foreach ($crawler->filter('#bodyTBody tr') as $row) {
            $cells = [];
            foreach ($row->childNodes as $cell) {
                if ($cell instanceof \DOMElement && $cell->tagName === 'td') {
                    $cells[] = $this->compact($cell->textContent);
                }
            }
            $fields['accepting_bodies'][] = $cells;
        }
        if (empty($fields['Наименование акта']) || empty($fields['accepting_bodies'])) {
            throw new SourceReviewException('Source metadata title or adopting requisites are missing.');
        }

        return $fields;
    }

    public function language(array $card): string
    {
        $raw = $card['Язык акта'] ?? '';
        $label = mb_strtolower(str_replace(['’', 'ʻ', '‘', 'ʼ'], "'", $this->compact($raw)));

        return match ($label) {
            'узбекский (л)', "o'zbekcha (l)" => 'uz-Latn', 'узбекский (к)', 'ўзбекча (к)', "o'zbekcha (k)" => 'uz-Cyrl', 'русский', 'ruscha', 'русча' => 'ru', 'английский', 'инглизча' => 'en',
            default => throw new SourceReviewException('Unknown source document language: '.mb_substr($raw, 0, 100)),
        };
    }

    public function availability(string $html, array $card, array $parsed): array
    {
        $body = (new Crawler($html))->filter('#divCont');
        $notice = $body->count() ? $this->compact($body->text()) : '';
        $absent = ($parsed['metadata_only'] ?? false) && mb_strlen($notice) <= 1000 && preg_match('/Текст акта приводится на|The text of the act is given in/ui', $notice);

        return ['status' => $absent ? 'not_available' : (($parsed['metadata_only'] ?? false) ? 'unknown' : 'available'),
            'type' => $absent ? 'explicit_translation_notice' : (($parsed['metadata_only'] ?? false) ? 'inconclusive_metadata_only' : 'source_card_representation'),
            'metadata' => ['raw_language_label' => $card['Язык акта'] ?? null, 'html_sha256' => hash('sha256', $html),
                ...($absent ? ['notice' => mb_substr($notice, 0, 500)] : [])]];
    }

    public function links(string $html, string $currentId, array $card): array
    {
        $links = [];
        $crawler = new Crawler($html);
        foreach ($crawler->filter('.docContentHeader__item-link[title]') as $element) {
            $label = mb_strtolower(str_replace(['’', 'ʻ', '‘', 'ʼ'], "'", $this->compact($element->textContent)));
            $language = match ($label) {
                "o'zb" => 'uz-Latn', 'ўзб' => 'uz-Cyrl', 'рус' => 'ru', 'eng' => 'en', default => null
            };
            if ($language === null) {
                if (preg_match('~openUrl\([\'"][^\'"]*/docs/-?\d+~', $element->getAttribute('onclick'))) {
                    throw new SourceReviewException('Unknown source language control requires review.');
                }

                continue;
            }
            if (preg_match("/openUrl\\(['\"]([^'\"]+)['\"]/u", $element->getAttribute('onclick'), $match)) {
                $url = $this->http->absolute($match[1]);
                $id = $this->externalId($url);
            } elseif (in_array('active', preg_split('/\s+/', $element->getAttribute('class')), true)) {
                $id = $currentId;
                $url = 'https://lex.uz/uz/docs/'.$currentId;
            } else {
                continue;
            }
            if (isset($links[$language]) && $links[$language]['id'] !== $id) {
                throw new SourceReviewException('Ambiguous source language links.');
            }
            $links[$language] = ['id' => $id, 'url' => $url];
        }
        if ($links === []) {
            // The PDF global interface menu is intentionally ignored.
            $links[$this->language($card)] = ['id' => $currentId, 'url' => 'https://lex.uz/uz/docs/'.$currentId];
        }

        return $links;
    }

    public function externalId(string $url): string
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
        if (in_array('ondate', array_map('strtolower', array_keys($query)), true)) {
            throw new SourceReviewException('Historical revision URL is not current document evidence.');
        }
        if (! preg_match('~/(?:docs|pdfs)/(-?\d+)$~D', parse_url($url, PHP_URL_PATH) ?? '', $match)) {
            throw new SourceReviewException('Source language URL is not a document URL.');
        }

        return $match[1];
    }

    public function requisites(array $card): array
    {
        $bodies = [];
        foreach ($card['accepting_bodies'] as $row) {
            if (count($row) < 6) {
                throw new SourceReviewException('Malformed adopting body row.');
            }
            $bodies[] = ['issuer' => $row[1], 'date' => $this->date($row[4]), 'number' => $row[5]];
        }
        usort($bodies, fn ($a, $b) => [$a['issuer'], $a['date'], $a['number']] <=> [$b['issuer'], $b['date'], $b['number']]);

        return ['type' => $card['Вид акта'] ?? null, 'form' => $card['Форма акта'] ?? null, 'bodies' => $bodies,
            'registration_number' => ($card['Регистрационный № МЮ'] ?? '') ?: null,
            'registration_date' => $this->date($card['Дата регистрации в МЮ'] ?? ''),
            'publication_date' => $this->date($card['Дата опубликования официального источника'] ?? '')];
    }

    public function status(array $card, ?string $listing = null): array
    {
        $raw = $card['Статус акта'] ?? '';
        $state = match ($raw) {
            'Действующий' => 'active',
            'Amalda' => $listing === 'Y' ? 'active' : 'unknown',
            'Утративший силу', 'Утратил силу', 'Принят в новой редакции' => 'revoked',
            'Не действующий' => 'not_in_force', default => 'unknown',
        };
        $date = $card['Дата вступления в силу'] ?? '';
        preg_match_all('/\d{2}\.\d{2}\.\d{4}/', $date, $matches);
        $dates = array_map(fn ($value) => $this->date($value), $matches[0]);
        $result = ['authority' => 'source_metadata', 'state' => $state, 'effective_at' => $dates[0] ?? null, 'effective_at_candidates' => $dates === [] ? [null] : $dates];
        if ($listing !== null) {
            $result['listing_state'] = match ($listing) {
                'Y' => 'active', 'R' => 'revoked', 'N' => $state === 'not_in_force' ? 'not_in_force' : 'revoked', default => 'unknown'
            };
        }

        return $result;
    }

    public function content(string $html, array $card): array
    {
        if (($url = $this->pdfUrl($html)) === null) {
            return $this->html->parse($html) + ['language' => $this->language($card)];
        }
        $response = $this->http->get($url, pdf: true);
        $row = $card['accepting_bodies'][0] ?? [];
        $parsed = $this->pdf->extract($response->body(), $response->header('Content-Type'), $this->language($card), [
            'document_number' => $row[5] ?? '', 'issued_date' => $row[4] ?? '',
            'registration_number' => $card['Регистрационный № МЮ'] ?? '',
        ]);

        return $parsed + ['metadata_only' => false];
    }

    public function wholeEffectiveDates(string $content): array
    {
        $months = ['yanvar' => 1, 'январ' => 1, 'fevral' => 2, 'феврал' => 2, 'mart' => 3, 'март' => 3, 'aprel' => 4, 'апрел' => 4, 'may' => 5, 'май' => 5, 'iyun' => 6, 'июн' => 6, 'iyul' => 7, 'июл' => 7, 'avgust' => 8, 'август' => 8, 'sentabr' => 9, 'сентябр' => 9, 'oktabr' => 10, 'октябр' => 10, 'noyabr' => 11, 'ноябр' => 11, 'dekabr' => 12, 'декабр' => 12];
        preg_match_all('/(?:Ушбу|Мазкур|Ushbu|Mazkur)\s+(?:қарор|Қонун|qaror|Qonun)\b.{0,220}?(?:кучга\s+киради|kuchga\s+kiradi)/ui', $this->compact($content), $sentences);
        $dates = [];
        foreach ($sentences[0] as $sentence) {
            if (preg_match('/\b(?:band|qism|банд|қисм)/ui', $sentence)) {
                continue;
            }
            if (preg_match('/(20\d{2})\s*[-–—]?\s*(?:yil|йил)\s*(\d{1,2})\s*[-–—]?\s*('.implode('|', array_keys($months)).')/ui', $sentence, $match)) {
                $month = $months[mb_strtolower($match[3])];
                if (checkdate($month, (int) $match[2], (int) $match[1])) {
                    $dates[] = sprintf('%04d-%02d-%02d', $match[1], $month, $match[2]);
                }
            }
        }

        return array_values(array_unique($dates));
    }

    public function date(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        if (! $date || $date->format('d.m.Y') !== $value) {
            throw new SourceReviewException('Invalid source metadata date.');
        }

        return $date->format('Y-m-d');
    }

    private function compact(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text));
    }
}
