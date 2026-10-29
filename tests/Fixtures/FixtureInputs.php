<?php

namespace Tests\Fixtures;

/** Offline translation of saved spike evidence into the portable P1 input contract. Not a production parser. */
final class FixtureInputs
{
    public static function read(string $path): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/docs/'.$path), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function seed(string $id = '8506802'): array
    {
        $manifest = self::read('p0/sample_manifest.json');
        $sample = array_values(array_filter($manifest, fn ($row) => $row['id'] === $id))[0];
        $graphs = self::read('p0_1/identity_graphs.json');
        $html = array_column(self::read('p0_1/html_results.json'), null, 'id');
        $pdf = array_column(self::read('p0_1/pdf_results.json'), null, 'id');
        $variants = $html[$id]['variants'] ?? $sample['variants'];
        $metadata = $sample['metadata'];
        $identity = [];
        $contents = [];
        foreach ($variants as $variant) {
            $language = $variant['language'];
            $url = $variant['url'];
            $external = basename(parse_url($url, PHP_URL_PATH));
            $node = $graphs[$id][$url] ?? null;
            $card = $node['metadata'] ?? $metadata;
            $onlyMetadata = $variant['skipped'] ?? false;
            $identity[] = [
                'external_id' => $external, 'language' => $language, 'source_url' => $url,
                'availability' => $onlyMetadata ? 'metadata_only' : 'full_text',
                'links' => $node ? array_values(array_map(fn ($link) => basename(parse_url($link, PHP_URL_PATH)), $node['links'])) : [$external],
                'requisites' => self::requisites($card),
            ];
            if ($onlyMetadata) {
                continue;
            }
            $fromP01 = isset($html[$id]);
            $path = ($fromP01 ? 'p0_1/' : 'p0/').$variant['markdown'];
            $markdown = file_get_contents(dirname(__DIR__, 2).'/docs/'.$path);
            $issues = $variant['quality']['issues'];
            $format = strtolower($variant['format'] ?? 'html');
            $quality = ['format' => $format, 'complete' => true, 'structure_verified' => $issues === [], 'issues' => $issues];
            if (isset($pdf[$id])) {
                $evidence = $pdf[$id];
                $language = $evidence['detected_language'];
                $identity[array_key_last($identity)]['language'] = $language;
                $markdown = file_get_contents(dirname(__DIR__, 2).'/docs/p0_1/'.$evidence['markdown']);
                $quality = ['format' => $evidence['ocr_pages'] === [] ? 'pdf' : 'ocr', 'complete' => $evidence['page_count_verified'], 'structure_verified' => false, 'issues' => $evidence['quality_issues'], 'pages_expected' => $evidence['pages'], 'pages_extracted' => count($evidence['page_stats'])];
            }
            $contents[$language] = ['title' => $card['Наименование акта'], 'content' => $markdown, 'quality' => $quality];
        }

        return [
            'entry_id' => $id, 'title' => $metadata['Наименование акта'],
            'document_type' => $metadata['Форма акта'], 'document_number' => $metadata['accepting_bodies'][0][5] ?: null,
            'source_url' => $sample['url'],
            'issued_at' => self::iso($metadata['accepting_bodies'][0][4]),
            'published_at' => self::iso($metadata['Дата опубликования официального источника'] ?? ''),
            'status_evidence' => self::status($metadata), 'identity' => $identity, 'contents' => $contents,
            'metadata' => ['fixture' => ['seed' => $id, 'source_card' => $metadata]],
        ];
    }

    public static function requisites(array $metadata): array
    {
        $bodies = array_map(fn ($row) => ['issuer' => $row[1], 'date' => self::iso($row[4]), 'number' => $row[5]], $metadata['accepting_bodies']);

        return [
            'type' => $metadata['Вид акта'], 'form' => $metadata['Форма акта'], 'bodies' => $bodies,
            'registration_number' => $metadata['Регистрационный № МЮ'] ?: null,
            'registration_date' => self::iso($metadata['Дата регистрации в МЮ']),
            'publication_date' => self::iso($metadata['Дата опубликования официального источника'] ?? ''),
        ];
    }

    public static function status(array $metadata): array
    {
        return ['authority' => 'source_metadata', 'state' => match ($metadata['Статус акта']) {
            'Действующий' => 'active', 'Утративший силу' => 'revoked', 'Не действующий' => 'not_in_force', default => 'unknown',
        }, 'effective_at' => self::iso($metadata['Дата вступления в силу'])];
    }

    public static function iso(string $date): ?string
    {
        return $date === '' ? null : \DateTimeImmutable::createFromFormat('!d.m.Y', $date)->format('Y-m-d');
    }
}
