<?php

namespace App\Services\Chunking;

use InvalidArgumentException;

class BoundedTextSplitter
{
    /** Split exact UTF-8 text, preferring natural boundaries. Offsets are relative bytes. */
    public function split(string $text, int $target, int $maximum, bool $table = false): iterable
    {
        if ($target < 80 || $target > $maximum || $maximum > 32000 || ! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException('Invalid bounded text policy or UTF-8.');
        }
        $offset = 0;
        $bytes = strlen($text);
        $previousFragment = false;
        while ($offset < $bytes) {
            $window = mb_strcut($text, $offset, $maximum * 4, 'UTF-8');
            if (mb_strlen($window) <= $maximum && $offset + strlen($window) === $bytes) {
                yield ['text' => $window, 'start' => $offset, 'end' => $bytes, 'boundary' => 'end', 'semantic_fragment' => $previousFragment];
                break;
            }
            $window = mb_substr($window, 0, $target, 'UTF-8');
            $cut = 0;
            $kind = 'utf8_fallback';
            $patterns = ['paragraph' => '/\R[\t ]*\R/u', 'line' => '/\R/u', 'sentence' => '/[.!?](?=\s)/u', 'clause' => '/[;:,](?=\s)/u', 'word' => '/\s/u'];
            foreach ($patterns as $label => $pattern) {
                preg_match_all($pattern, $window, $matches, PREG_OFFSET_CAPTURE);
                if ($matches[0] !== []) {
                    [$separator, $at] = $matches[0][array_key_last($matches[0])];
                    $candidate = $at + strlen($separator);
                    if ($candidate >= strlen($window) * 0.5) {
                        $cut = $candidate;
                        $kind = $label;
                        break;
                    }
                }
            }
            if ($cut === 0) {
                $cut = strlen($window);
            }
            if ($cut === 0) {
                throw new \LogicException('No progress at a UTF-8 boundary.');
            }
            $currentFragment = in_array($kind, ['word', 'utf8_fallback'], true) || ($table && ! in_array($kind, ['line', 'paragraph'], true));
            yield ['text' => substr($window, 0, $cut), 'start' => $offset, 'end' => $offset + $cut,
                'boundary' => $table && $kind === 'line' ? 'table_row' : $kind,
                'semantic_fragment' => $previousFragment || $currentFragment];
            $previousFragment = $currentFragment;
            $offset += $cut;
        }
    }
}
