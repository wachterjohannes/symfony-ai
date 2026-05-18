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
 * Route-specific deep dive. All data comes from the node's own metadata plus a depth-2 walk
 * to surface the controller and its service dependencies — no extra I/O.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RouteInspector implements InspectorInterface
{
    private const NEIGHBOURHOOD_DEPTH = 2;

    public function supports(GraphNode $node): bool
    {
        return 'route' === $node->type;
    }

    public function inspect(GraphNode $node, RuntimeGraph $graph, array $include): GraphResult
    {
        $view = $graph->neighbors($node->id, self::NEIGHBOURHOOD_DEPTH);

        $methods = (array) ($node->metadata['methods'] ?? []);
        $path = (string) ($node->metadata['path'] ?? '/');
        $defaults = (array) ($node->metadata['defaults'] ?? []);
        $controller = isset($defaults['_controller']) ? (string) $defaults['_controller'] : null;

        $findings = [
            \sprintf('Path: %s', $path),
            \sprintf('Methods: %s', [] === $methods ? 'ANY' : implode('|', array_map('strval', $methods))),
        ];
        if (null !== $controller) {
            $findings[] = \sprintf('Controller: %s', $controller);
        }
        $extraDefaults = array_diff_key($defaults, ['_controller' => true]);
        if ([] !== $extraDefaults) {
            $findings[] = \sprintf('Default params: %s', implode(', ', array_keys($extraDefaults)));
        }

        return new GraphResult(
            summary: \sprintf('Route %s (%s %s).', $node->label, [] === $methods ? 'ANY' : implode('|', array_map('strval', $methods)), $path),
            primaryNodes: [$this->nodeToArray($node)],
            findings: $findings,
            evidence: $this->edgesToEvidence($view->edges),
            relatedNodes: $this->relatedNodesExcluding($view->nodes, $node->id),
            nextActions: [
                ['tool' => 'symfony-graph', 'args' => ['node' => $node->id, 'depth' => 3]],
                ['tool' => 'symfony-context', 'args' => ['target' => $node->id]],
            ],
        );
    }

    /**
     * @param list<\Symfony\AI\Mate\Bridge\Symfony\Graph\GraphEdge> $edges
     *
     * @return list<array{kind: string, from: string, relation: string, to: string}>
     */
    private function edgesToEvidence(array $edges): array
    {
        $rows = [];
        foreach ($edges as $edge) {
            $rows[] = [
                'kind' => 'edge',
                'from' => $edge->from,
                'relation' => $edge->relation,
                'to' => $edge->to,
            ];
        }

        return $rows;
    }

    /**
     * @param list<GraphNode> $nodes
     *
     * @return list<array{id: string, type: string, label: string, metadata: array<string, mixed>}>
     */
    private function relatedNodesExcluding(array $nodes, string $excludeId): array
    {
        $related = [];
        foreach ($nodes as $node) {
            if ($node->id === $excludeId) {
                continue;
            }
            $related[] = $this->nodeToArray($node);
        }

        return $related;
    }

    /**
     * @return array{id: string, type: string, label: string, metadata: array<string, mixed>}
     */
    private function nodeToArray(GraphNode $node): array
    {
        return [
            'id' => $node->id,
            'type' => $node->type,
            'label' => $node->label,
            'metadata' => $node->metadata,
        ];
    }
}
