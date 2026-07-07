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
 * Immutable subview of a {@see RuntimeGraph} returned by neighborhood traversals.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final readonly class GraphView
{
    /**
     * @param list<GraphNode> $nodes
     * @param list<GraphEdge> $edges
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public bool $truncated = false,
    ) {
    }
}
