<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Capability;

use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphView;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * MCP tool that walks the runtime graph from a starting node and returns the raw
 * nodes + edges subview.
 *
 * Use {@see ContextTool} for an opinionated, narrative envelope; use this tool when the
 * caller already knows what they're looking for and wants the structural data.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class GraphTool
{
    public function __construct(
        private readonly StaticGraphFactory $factory,
    ) {
    }

    /**
     * @param string       $node      Starting node id (e.g. "service:App\\Service\\Foo").
     * @param int          $depth     Number of hops to follow. 0 returns just the starting node.
     * @param list<string> $relations Filter edges to these relations (empty = all).
     * @param int          $maxNodes  Soft cap; when hit, `truncated` is `true`.
     */
    #[McpTool(
        name: 'symfony-graph',
        description: 'Traverse the runtime graph from a starting node. Returns the raw nodes/edges subview within the requested depth, optionally filtered by edge relation.',
    )]
    public function getGraph(
        string $node,
        int $depth = 2,
        array $relations = [],
        int $maxNodes = 50,
    ): string {
        return ResponseEncoder::encode($this->build($node, $depth, $relations, $maxNodes));
    }

    /**
     * Plain array entry point used by {@see \Symfony\AI\Mate\Bridge\Symfony\Operation\GraphToolBox}
     * and any other in-process caller that needs the structured subview directly rather than the
     * MCP-encoded string. {@see getGraph()} is a thin wrapper that encodes the same payload.
     *
     * @param list<string> $relations
     *
     * @return array{
     *     nodes: list<array{id: string, type: string, label: string, metadata: array<string, mixed>}>,
     *     edges: list<array{from: string, relation: string, to: string}>,
     *     truncated: bool,
     *     suggestedFocus: list<string>,
     * }
     */
    public function build(string $node, int $depth = 2, array $relations = [], int $maxNodes = 50): array
    {
        $graph = $this->factory->build();
        $view = $graph->neighbors($node, $depth, $relations, $maxNodes);

        $nodes = [];
        foreach ($view->nodes as $graphNode) {
            $nodes[] = [
                'id' => $graphNode->id,
                'type' => $graphNode->type,
                'label' => $graphNode->label,
                'metadata' => $graphNode->metadata,
            ];
        }

        $edges = [];
        foreach ($view->edges as $edge) {
            $edges[] = [
                'from' => $edge->from,
                'relation' => $edge->relation,
                'to' => $edge->to,
            ];
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'truncated' => $view->truncated,
            'suggestedFocus' => $this->pickFocus($view),
        ];
    }

    /**
     * @return list<string>
     */
    private function pickFocus(GraphView $view): array
    {
        $degree = [];
        foreach ($view->edges as $edge) {
            $degree[$edge->from] = ($degree[$edge->from] ?? 0) + 1;
            $degree[$edge->to] = ($degree[$edge->to] ?? 0) + 1;
        }

        arsort($degree);

        $focus = [];
        foreach (array_keys($degree) as $id) {
            $focus[] = (string) $id;
            if (\count($focus) >= 3) {
                break;
            }
        }

        return $focus;
    }
}
