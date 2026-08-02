<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Graph;

/**
 * Mutable holder for the Symfony runtime graph.
 *
 * Nodes are deduplicated by id; edges are deduplicated by the triple (from, relation, to).
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class RuntimeGraph
{
    /**
     * @var array<string, GraphNode>
     */
    private array $nodes = [];

    /**
     * @var array<string, GraphEdge>
     */
    private array $edges = [];

    /**
     * Lazily-built adjacency index keyed by node id. Holds every edge that touches the node
     * (both incoming and outgoing). Invalidated to null whenever a node or edge is added.
     *
     * @var array<string, list<GraphEdge>>|null
     */
    private ?array $adjacencyIndex = null;

    public function addNode(GraphNode $node): void
    {
        if (!isset($this->nodes[$node->id])) {
            $this->nodes[$node->id] = $node;
            $this->adjacencyIndex = null;
        }
    }

    public function addEdge(GraphEdge $edge): void
    {
        $key = $edge->from."\0".$edge->relation."\0".$edge->to;
        if (!isset($this->edges[$key])) {
            $this->edges[$key] = $edge;
            $this->adjacencyIndex = null;
        }
    }

    public function node(string $id): ?GraphNode
    {
        return $this->nodes[$id] ?? null;
    }

    public function hasNode(string $id): bool
    {
        return isset($this->nodes[$id]);
    }

    /**
     * @return array<string, GraphNode>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<GraphEdge>
     */
    public function edges(): array
    {
        return array_values($this->edges);
    }

    /**
     * Breadth-first traversal returning all nodes reachable from $id within $depth hops.
     *
     * Edges are followed in both directions (incoming and outgoing). The starting node is
     * always included in the returned view; depth 0 means "only the starting node, no edges."
     *
     * @param list<string> $relations restrict to these relations (empty = all)
     */
    public function neighbors(string $id, int $depth = 1, array $relations = [], int $maxNodes = 50): GraphView
    {
        if (!isset($this->nodes[$id])) {
            return new GraphView([], [], false);
        }

        $relationFilter = [] === $relations ? null : array_flip($relations);
        $adjacency = $this->adjacency();

        $visited = [$id => true];
        $frontier = [$id];
        $resultNodes = [$id => $this->nodes[$id]];
        $resultEdges = [];
        $truncated = false;

        for ($hop = 0; $hop < $depth && [] !== $frontier; ++$hop) {
            $nextFrontier = [];
            foreach ($frontier as $current) {
                foreach ($adjacency[$current] ?? [] as $edge) {
                    if (null !== $relationFilter && !isset($relationFilter[$edge->relation])) {
                        continue;
                    }

                    $other = $edge->from === $current ? $edge->to : $edge->from;
                    if (!isset($this->nodes[$other])) {
                        continue;
                    }

                    if (!isset($resultNodes[$other])) {
                        if (\count($resultNodes) >= $maxNodes) {
                            $truncated = true;
                            continue;
                        }
                        $resultNodes[$other] = $this->nodes[$other];
                    }

                    $edgeKey = $edge->from."\0".$edge->relation."\0".$edge->to;
                    if (!isset($resultEdges[$edgeKey])) {
                        $resultEdges[$edgeKey] = $edge;
                    }

                    if (!isset($visited[$other])) {
                        $visited[$other] = true;
                        $nextFrontier[] = $other;
                    }
                }
            }
            $frontier = $nextFrontier;
        }

        return new GraphView(array_values($resultNodes), array_values($resultEdges), $truncated);
    }

    /**
     * Returns the shortest undirected path between $from and $to, or null if unreachable.
     *
     * Cycles are handled by visited-set tracking.
     *
     * @param list<string> $relations Restrict the walk to edges with these relations (empty = all)
     */
    public function path(string $from, string $to, array $relations = []): ?GraphPath
    {
        if (!isset($this->nodes[$from]) || !isset($this->nodes[$to])) {
            return null;
        }

        if ($from === $to) {
            return new GraphPath([$this->nodes[$from]], []);
        }

        $relationFilter = [] === $relations ? null : array_flip($relations);
        $adjacency = $this->adjacency();

        /** @var array<string, string|null> $previous */
        $previous = [$from => null];
        /** @var array<string, GraphEdge> $arrivedVia */
        $arrivedVia = [];
        $queue = [$from];

        while ([] !== $queue) {
            $current = array_shift($queue);
            if ($current === $to) {
                break;
            }

            foreach ($adjacency[$current] ?? [] as $edge) {
                if (null !== $relationFilter && !isset($relationFilter[$edge->relation])) {
                    continue;
                }

                $other = $edge->from === $current ? $edge->to : $edge->from;
                if (\array_key_exists($other, $previous)) {
                    continue;
                }

                $previous[$other] = $current;
                $arrivedVia[$other] = $edge;
                $queue[] = $other;
            }
        }

        if (!\array_key_exists($to, $previous)) {
            return null;
        }

        $nodes = [];
        $edges = [];
        $cursor = $to;
        while (null !== $cursor) {
            $nodes[] = $this->nodes[$cursor];
            if (isset($arrivedVia[$cursor])) {
                $edges[] = $arrivedVia[$cursor];
            }
            $cursor = $previous[$cursor];
        }

        return new GraphPath(array_reverse($nodes), array_reverse($edges));
    }

    /**
     * @return array<string, list<GraphEdge>>
     */
    private function adjacency(): array
    {
        if (null !== $this->adjacencyIndex) {
            return $this->adjacencyIndex;
        }

        $adjacency = [];
        foreach ($this->edges as $edge) {
            $adjacency[$edge->from][] = $edge;
            if ($edge->from !== $edge->to) {
                $adjacency[$edge->to][] = $edge;
            }
        }

        return $this->adjacencyIndex = $adjacency;
    }
}
