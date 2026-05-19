<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Operation;

use Symfony\AI\Mate\Bridge\Symfony\Capability\ContextTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphResult;
use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\InspectTool;

/**
 * Thin façade exposing the underlying graph tools to recipes without MCP framing.
 *
 * Recipes call `context()` / `graph()` / `inspect()` instead of going through the
 * encoded MCP entry points, so they receive structured PHP values directly. Each
 * delegate hits the same `build()` method the MCP tool wraps, keeping behaviour
 * identical to what an agent would observe.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class GraphToolBox
{
    public function __construct(
        private readonly ContextTool $contextTool,
        private readonly GraphTool $graphTool,
        private readonly InspectTool $inspectTool,
    ) {
    }

    /**
     * @param list<string> $include
     */
    public function context(string $target, array $include = ['route', 'controller', 'services'], int $maxItems = 20): GraphResult
    {
        return $this->contextTool->build($target, $include, $maxItems);
    }

    /**
     * @param list<string> $relations
     *
     * @return array{
     *     nodes: list<array{id: string, type: string, label: string, metadata: array<string, mixed>}>,
     *     edges: list<array{from: string, relation: string, to: string}>,
     *     truncated: bool,
     *     suggestedFocus: list<string>,
     * }
     */
    public function graph(string $node, int $depth = 2, array $relations = [], int $maxNodes = 50): array
    {
        return $this->graphTool->build($node, $depth, $relations, $maxNodes);
    }

    /**
     * @param list<string> $include
     */
    public function inspect(string $node, array $include = []): GraphResult
    {
        return $this->inspectTool->build($node, $include);
    }
}
