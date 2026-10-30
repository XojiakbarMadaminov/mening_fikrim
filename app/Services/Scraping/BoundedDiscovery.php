<?php

namespace App\Services\Scraping;

use App\Scraping\LexUz\LexHttpClient;
use App\Scraping\LexUz\LexUzScraper;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

/** Finite coverage probe, independent of import selection. No source adapter writes to DB. */
class BoundedDiscovery
{
    public function __construct(private LexUzScraper $scraper, private DiscoveryPlanner $planner, private PilotCampaign $pilot) {}

    public function collect(string $name, array $query, int $pageCap = 8, int $maxDepth = 10, int $partitionCap = 63, ?callable $afterPage = null, ?int $singleDayPageCap = null): array
    {
        $this->pilot->assertOpen();
        $singleDayPageCap ??= $pageCap;
        if (! preg_match('/^[a-z0-9-]{1,60}$/D', $name) || $pageCap < 1 || $pageCap > 8 || $maxDepth < 0 || $maxDepth > 16 || $partitionCap < 1 || $partitionCap > 127 || $singleDayPageCap < $pageCap || $singleDayPageCap > 64) {
            throw new InvalidArgumentException('Discovery must retain finite page/depth/partition caps.');
        }
        $this->planner->date($query['from'] ?? '');
        $this->planner->date($query['to'] ?? '');
        $signature = $this->planner->signature($query);
        $key = 'lex:discovery:'.($this->pilot->id() ?? 'offline').':'.$name;
        $bounds = [$signature, $pageCap, $maxDepth, $partitionCap];
        if ($singleDayPageCap !== $pageCap) {
            $bounds[] = $singleDayPageCap;
        }
        $durable = app(DurableCampaign::class);
        $permanent = $durable->exists();
        if ($permanent) {
            $configuration = $durable->state()['configuration_snapshot'];
            if ($pageCap > $configuration['page_cap'] || $maxDepth > $configuration['max_partition_depth'] || $singleDayPageCap > ($configuration['single_day_page_cap'] ?? $configuration['page_cap'])) {
                throw new InvalidArgumentException('Discovery cannot exceed the fixed campaign page/depth caps.');
            }
        }
        $state = ($permanent ? $durable->loadCheckpoint($name, $bounds) : Cache::get($key)) ?? ['bounds' => $bounds, 'nodes' => []];
        if ($state['bounds'] !== $bounds) {
            throw new RuntimeException('Cannot reset a discovery checkpoint with different bounds.');
        }
        $save = function () use (&$state, $key, $permanent, $durable, $name, $bounds): void {
            if ($permanent) {
                $durable->saveCheckpoint($name, $bounds, $state);
            } else {
                Cache::forever($key, $state);
            }
        };
        $visit = function (array $segment, int $depth) use (&$visit, &$state, $save, $pageCap, $maxDepth, $partitionCap, $afterPage, $singleDayPageCap, $permanent, $durable): array {
            $id = $this->planner->signature($segment);
            if (($state['nodes'][$id]['done'] ?? false) === true) {
                return $state['nodes'][$id];
            }
            if (! isset($state['nodes'][$id])) {
                if (count($state['nodes']) >= $partitionCap) {
                    return ['query' => $segment, 'complete' => false, 'reason' => 'partition_cap', 'rows' => [], 'total' => null];
                }
                $state['nodes'][$id] = ['query' => $segment, 'depth' => $depth, 'pages' => [], 'rows' => [], 'done' => false];
                $save();
            }
            $node = $state['nodes'][$id];
            if (! isset($node['enumeration'])) {
                $effectiveBudget = $segment['from'] === $segment['to'] ? $singleDayPageCap : $pageCap;
                if ($permanent && ($durable->state()['configuration_snapshot']['discovery_node_contexts'] ?? false)) {
                    app(LexHttpClient::class)->context('discovery-'.substr($id, 0, 40));
                }
                $result = $this->scraper->discover($segment, function ($page) use (&$state, $id, $save, $effectiveBudget, $afterPage): bool {
                    $old = $state['nodes'][$id]['pages'][$page['page']] ?? null;
                    $proof = ['total' => $page['total'], 'prefix' => $page['prefix_fingerprint'], 'fingerprint' => $page['fingerprint']];
                    if ($old !== null && $old !== $proof) {
                        throw new RuntimeException('Discovery committed prefix or total drifted.');
                    }
                    $state['nodes'][$id]['pages'][$page['page']] = $proof;
                    foreach ($page['rows'] as $row) {
                        $state['nodes'][$id]['rows'][(string) $row['id']] = $row;
                    }
                    if (count($state['nodes'][$id]['rows']) > 3000) {
                        throw new RuntimeException('Discovery finite ID bound exceeded.');
                    }
                    $save();
                    if ($afterPage !== null) {
                        $afterPage($page, $id);
                    }

                    // A page-size-derived overflow is a reason to split, never proof of completeness.
                    return $page['page'] !== 1 || $page['total'] <= max(1, count($page['rows'])) * $effectiveBudget;
                }, $effectiveBudget);
                $state['nodes'][$id]['enumeration'] = $result;
                $save();
                $node = $state['nodes'][$id];
            }
            $node['total'] = $node['enumeration']['total'];
            if ($node['enumeration']['complete']) {
                $node['complete'] = true;
            } elseif ($depth >= $maxDepth || $segment['from'] === $segment['to']) {
                $node['complete'] = false;
                $node['reason'] = $depth >= $maxDepth ? 'maximum_depth' : 'single_day_overflow';
            } else {
                $children = [];
                $rows = [];
                foreach ($this->planner->split($segment) as $child) {
                    $part = $visit($child, $depth + 1);
                    $children[] = ['signature' => $this->planner->signature($child), 'complete' => $part['complete'], 'reason' => $part['reason'] ?? null];
                    $rows = array_replace($rows, $part['rows']);
                }
                // Parent prefix IDs must also appear in the child union, not silently disappear.
                $node['complete'] = ! in_array(false, array_column($children, 'complete'), true)
                    && count($rows) === $node['total'] && array_diff_key($node['rows'], $rows) === [];
                $node['reason'] = $node['complete'] ? null : 'child_coverage_or_count_mismatch';
                $node['children'] = $children;
                $node['rows'] = array_replace($node['rows'], $rows);
            }
            $node['done'] = true;
            $state['nodes'][$id] = $node;
            $save();

            return $node;
        };
        $root = $visit($query, 0);

        return ['root' => $root, 'nodes' => $state['nodes'], 'complete' => $root['complete'], 'rows' => array_values($root['rows']), 'cache_key' => $key];
    }
}
