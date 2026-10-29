<?php

namespace App\Services\Scraping;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

class DiscoveryPlanner
{
    public const ROOTS = ['nat', 'loc', 'int', 'tech', 'court', 'oliy'];

    /** The signature includes every accepted query filter, including language if used in historical evidence. */
    public function signature(array $segment): string
    {
        $query = array_diff_key($segment, array_flip(['ancestors']));
        ksort($query);

        return hash('sha256', json_encode($query, JSON_THROW_ON_ERROR));
    }

    public function split(array $segment): array
    {
        $from = $this->date($segment['from'] ?? '');
        $to = $this->date($segment['to'] ?? '');
        if ($from > $to) {
            throw new InvalidArgumentException('Discovery dates are reversed.');
        }
        $days = (int) $from->diff($to)->days;
        if ($days === 0) {
            throw new RuntimeException('coverage_gap: a single day exceeds the segment target; no silent truncation.');
        }
        $middle = $from->modify('+'.intdiv($days, 2).' days');
        $ancestors = [...($segment['ancestors'] ?? []), $this->signature($segment)];

        return [array_replace($segment, ['to' => $middle->format('d.m.Y'), 'ancestors' => $ancestors]),
            array_replace($segment, ['from' => $middle->modify('+1 day')->format('d.m.Y'), 'ancestors' => $ancestors])];
    }

    public function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!d.m.Y', $value);
        if (! $date || $date->format('d.m.Y') !== $value) {
            throw new InvalidArgumentException('Discovery dates must be real dd.mm.yyyy dates.');
        }

        return $date;
    }
}
