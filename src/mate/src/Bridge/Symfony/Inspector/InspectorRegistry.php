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

use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode;

/**
 * Resolves the first {@see InspectorInterface} that supports a given node.
 *
 * Inspectors are collected via the `ai_mate.graph_inspector` DI tag; iteration order is
 * deterministic across builds since the tagged iterator preserves registration order.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class InspectorRegistry
{
    /**
     * @param iterable<InspectorInterface> $inspectors
     */
    public function __construct(
        private readonly iterable $inspectors,
    ) {
    }

    public function inspectorFor(GraphNode $node): ?InspectorInterface
    {
        foreach ($this->inspectors as $inspector) {
            if ($inspector->supports($node)) {
                return $inspector;
            }
        }

        return null;
    }
}
