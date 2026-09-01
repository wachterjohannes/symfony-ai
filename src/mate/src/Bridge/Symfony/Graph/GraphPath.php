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
 * The result of a shortest-path search through a {@see RuntimeGraph}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final readonly class GraphPath
{
    /**
     * @param list<GraphNode> $nodes
     * @param list<GraphEdge> $edges
     */
    public function __construct(
        public array $nodes,
        public array $edges,
    ) {
    }
}
