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
 * Thin mutating wrapper around {@see RuntimeGraph} passed to graph providers.
 *
 * Providers stay decoupled from {@see GraphNode}/{@see GraphEdge} construction details and
 * only call {@see self::node()} / {@see self::edge()}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class RuntimeGraphBuilder
{
    public function __construct(
        private readonly RuntimeGraph $graph,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function node(string $id, string $type, string $label, array $metadata = []): void
    {
        $this->graph->addNode(new GraphNode($id, $type, $label, $metadata));
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function edge(string $from, string $relation, string $to, array $metadata = []): void
    {
        $this->graph->addEdge(new GraphEdge($from, $relation, $to, $metadata));
    }

    public function has(string $id): bool
    {
        return $this->graph->hasNode($id);
    }

    public function graph(): RuntimeGraph
    {
        return $this->graph;
    }
}
