<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Inspector;

use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphResult;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;

/**
 * Contract for components that produce a deep dive on a single graph node.
 *
 * Inspectors are dispatched by {@see InspectorRegistry} based on {@see self::supports()};
 * the active runtime graph (static or request-scoped) is passed through so each inspector
 * can walk its neighbourhood without rebuilding it.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface InspectorInterface
{
    public function supports(GraphNode $node): bool;

    /**
     * @param list<string> $include Advisory hints — interpretation is per-inspector
     */
    public function inspect(GraphNode $node, RuntimeGraph $graph, array $include): GraphResult;
}
