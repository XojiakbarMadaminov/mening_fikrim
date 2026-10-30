<?php

// Fixed monthly intervals: finite discovery, never an unrestricted crawl.
$collectionScope = [];
foreach (range(2021, 2024) as $year) {
    foreach (range(1, 12) as $month) {
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        foreach (['nat', 'loc', 'oliy'] as $section) {
            $collectionScope[] = ['section' => $section, 'status' => 'Y', 'from' => $from->format('d.m.Y'), 'to' => $from->format('t.m.Y')];
        }
    }
}

$largeScope = [['section' => 'loc', 'status' => 'Y', 'from' => '30.12.2021', 'to' => '30.12.2021']];
foreach (range(2015, 2025) as $year) {
    foreach (range(1, 12) as $month) {
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        foreach (['nat', 'loc', 'oliy'] as $section) {
            $largeScope[] = ['section' => $section, 'status' => 'Y', 'from' => $from->format('d.m.Y'), 'to' => $from->format('t.m.Y')];
        }
    }
}

return [
    'corpus-30000-20261030' => [
        'predecessor' => 'corpus-10000-20261029',
        'successor_stop_reasons' => ['unreconciled_discovery_partition'],
        'extended_collection' => true,
        'requests' => 360000, 'sources' => 75000, 'target' => 30000,
        'stages' => [30, 100, 300, 1000, 3000, 6000, 10000, 20000, 30000],
        'limits' => [
            'max_campaign_seconds' => 1814400, 'max_partitions' => 8192, 'max_discovered_ids' => 500000,
            'single_day_page_cap' => 64, 'max_request_contexts' => 16384,
            'discovery_node_contexts' => true, 'replace_consumed_pool' => true,
            'discovery_gap_policy' => 'record_and_continue',
        ],
        'scope' => $largeScope,
    ],
    'corpus-10000-20261029' => [
        'predecessor' => 'p6-1-completion-150-20261029',
        'extended_collection' => true,
        'requests' => 120000, 'sources' => 25000, 'target' => 10000,
        'stages' => [30, 100, 300, 1000, 3000, 6000, 10000],
        'limits' => ['max_campaign_seconds' => 604800, 'max_partitions' => 1024, 'max_discovered_ids' => 100000],
        'scope' => $collectionScope,
    ],
    'p6-1-completion-150-20261029' => [
        'predecessor' => 'p6-corpus-1000-20261029',
        'requests' => 3500, 'sources' => 600, 'target' => 150, 'stages' => [15, 50, 100, 150],
        'scope' => [
            ['section' => 'nat', 'status' => 'Y', 'from' => '01.03.2020', 'to' => '31.03.2020'],
            ['section' => 'loc', 'status' => 'Y', 'from' => '01.03.2020', 'to' => '31.03.2020'],
            ['section' => 'oliy', 'status' => 'Y', 'from' => '01.01.2021', 'to' => '31.03.2021'],
            ['section' => 'oliy', 'status' => 'Y', 'from' => '01.04.2021', 'to' => '30.06.2021'],
            ['section' => 'nat', 'status' => 'Y', 'from' => '01.04.2020', 'to' => '30.04.2020'],
            ['section' => 'loc', 'status' => 'Y', 'from' => '01.04.2020', 'to' => '30.04.2020'],
        ],
    ],
];
