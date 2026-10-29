<?php

namespace Tests\Fixtures;

/** Controlled transport reconstructions from saved P0/P0.1 metadata and short source excerpts. */
class LexResponses
{
    public static function card(array $metadata): string
    {
        $html = '<html><head><meta charset="UTF-8"></head><body><table>';
        foreach ($metadata as $key => $value) {
            if ($key !== 'accepting_bodies') {
                $html .= '<tr><td class="lbl">'.htmlspecialchars($key).'</td><td>'.htmlspecialchars($value).'</td></tr>';
            }
        }
        $html .= '</table><table><tbody id="bodyTBody">';
        foreach ($metadata['accepting_bodies'] as $row) {
            $html .= '<tr>'.implode('', array_map(fn ($cell) => '<td>'.htmlspecialchars($cell).'</td>', $row)).'</tr>';
        }

        return $html.'</tbody></table></body></html>';
    }

    public static function graph(): array
    {
        $graph = FixtureInputs::read('p0_1/identity_graphs.json')['8506802'];
        $source = FixtureInputs::read('p0/source_fixtures.json')['8506802'];
        $manifest = array_values(array_filter(FixtureInputs::read('p0/sample_manifest.json'), fn ($row) => $row['id'] === '8506802'))[0];
        $responses = [];
        foreach ($graph as $url => $node) {
            $html = '<html><head><meta charset="UTF-8"></head><body><script>showIframeCard(\'/uz/actinfo/card1/'.$node['id'].'\');</script>';
            foreach ($node['links'] as $language => $target) {
                $label = ['uz-Latn' => 'O’zb', 'uz-Cyrl' => 'Ўзб', 'ru' => 'Рус', 'en' => 'Eng'][$language];
                $html .= '<div class="docContentHeader__item-link" title="language" onclick="openUrl(\''.parse_url($target, PHP_URL_PATH).'\')">'.$label.'</div>';
            }
            $variant = array_values(array_filter($manifest['variants'], fn ($row) => $row['url'] === $url))[0];
            if ($node['id'] === '8506802') {
                $html .= $source['excerpt_html'];
            } else {
                $markdown = file_get_contents(dirname(__DIR__, 2).'/docs/p0/'.$variant['markdown']);
                $excerpt = implode("\n\n", array_slice(explode("\n\n", $markdown), 1, 3));
                $html .= '<div id="divCont"><div class="ACT_TITLE">'.htmlspecialchars($node['metadata']['Наименование акта']).'</div><div class="ACT_TEXT">'.htmlspecialchars($excerpt).'</div></div>';
            }
            $responses[$url] = $html.'</body></html>';
            $responses['https://lex.uz/uz/actinfo/card1/'.$node['id']] = self::card($node['metadata']);
        }

        return $responses;
    }

    public static function listing(array $ids, int $total, ?string $next = null, string $state = 'state-secret'): string
    {
        $html = '<html><head><meta charset="UTF-8"></head><body><form id="Form1"><input type="hidden" name="__VIEWSTATE" value="'.$state.'"><input type="hidden" name="__VIEWSTATEGENERATOR" value="generation-secret"><input type="hidden" name="EXTRA_STATE" value="extra-secret"></form><div>'.$total.' hujjat topildi</div><table>';
        foreach ($ids as $id) {
            $html .= '<tr><td><span class="status_code_y"></span><a class="lx_link" href="/uz/docs/'.$id.'">Saved source document '.$id.'</a></td></tr>';
        }
        $html .= '</table>';
        if ($next !== null) {
            $html .= '<a id="ucFoundActsControl_LinkButton1" href="javascript:__doPostBack(\''.$next.'\',\'\')">Next</a>';
        }

        return $html.'</body></html>';
    }
}
