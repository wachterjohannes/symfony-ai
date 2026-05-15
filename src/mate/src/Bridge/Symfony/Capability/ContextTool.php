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
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * MCP tool that returns a compact, connected runtime view (GraphResult envelope) for a
 * requested target.
 *
 * Supported targets in this iteration: `static`, `route:<name>`, `service:<id>`. Request-
 * scoped targets (`latest_request`, `request:<token>`) are wired up in the follow-up
 * profiler-graph PR; until then they return a warning explaining the limitation.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ContextTool
{
    public function __construct(
        private readonly StaticGraphFactory $factory,
    ) {
    }

    /**
     * @param string       $target   One of: 'static', 'route:<name>', 'service:<id>'.
     *                               Request-scoped targets are not yet supported.
     * @param list<string> $include  Hints which neighbour categories matter most (currently
     *                               advisory — used to bias `findings` and `nextActions`).
     * @param int          $maxItems Soft cap on the size of the returned neighbourhood.
     */
    #[McpTool(
        name: 'symfony-context',
        description: 'Return a compact, connected runtime view for a target node ("service:Foo", "route:app_checkout", or "static" for an app-wide summary). Bundles primary nodes, related nodes, evidence edges, and suggested follow-up tools in a single response.',
    )]
    public function getContext(
        string $target = 'static',
        array $include = ['route', 'controller', 'services'],
        int $maxItems = 20,
    ): string {
        if (\in_array($target, ['latest_request', 'request', ''], true) || str_starts_with($target, 'request:')) {
            $result = new GraphResult(
                summary: 'Request-scoped context is not yet supported by this tool — the profiler graph provider lands in a follow-up PR.',
                primaryNodes: [],
                findings: [],
                evidence: [],
                relatedNodes: [],
                nextActions: [],
                warnings: ['Target "'.$target.'" is not yet supported — see the runtime-graph epic in symfony/ai for the profiler-graph follow-up.'],
            );

            return ResponseEncoder::encode($result->toArray());
        }

        $graph = $this->factory->build();

        if ('static' === $target) {
            return ResponseEncoder::encode($this->buildStaticOverview($graph, $maxItems)->toArray());
        }

        $node = $graph->node($target);
        if (null === $node) {
            $result = new GraphResult(
                summary: \sprintf('No node with id "%s" found in the runtime graph.', $target),
                primaryNodes: [],
                findings: [],
                evidence: [],
                relatedNodes: [],
                nextActions: [],
                warnings: ['Unknown target — make sure the target Symfony app has built its container cache and (for routes) warmed its router.'],
            );

            return ResponseEncoder::encode($result->toArray());
        }

        $view = $graph->neighbors($target, 2, [], $maxItems);

        $primary = [$this->nodeToArray($node)];
        $related = [];
        foreach ($view->nodes as $other) {
            if ($other->id === $target) {
                continue;
            }
            $related[] = $this->nodeToArray($other);
        }

        $evidence = [];
        foreach ($view->edges as $edge) {
            $evidence[] = [
                'kind' => 'edge',
                'from' => $edge->from,
                'relation' => $edge->relation,
                'to' => $edge->to,
            ];
        }

        $result = new GraphResult(
            summary: $this->summarise($node, $view->edges, $related),
            primaryNodes: $primary,
            findings: $this->collectFindings($node, $view->edges, $include),
            evidence: $evidence,
            relatedNodes: $related,
            nextActions: $this->suggestNextActions($node),
            warnings: $view->truncated ? ['Neighbourhood truncated at maxItems='.$maxItems.'; raise maxItems for a wider view.'] : [],
        );

        return ResponseEncoder::encode($result->toArray());
    }

    private function buildStaticOverview(RuntimeGraph $graph, int $maxItems): GraphResult
    {
        $serviceCount = 0;
        $routeCount = 0;
        $controllerCount = 0;
        $interfaceCount = 0;
        foreach ($graph->nodes() as $node) {
            switch ($node->type) {
                case 'service':
                    ++$serviceCount;
                    break;
                case 'route':
                    ++$routeCount;
                    break;
                case 'controller':
                    ++$controllerCount;
                    break;
                case 'interface':
                    ++$interfaceCount;
                    break;
            }
        }

        $dependsOn = 0;
        $handledBy = 0;
        foreach ($graph->edges() as $edge) {
            if ('depends_on' === $edge->relation) {
                ++$dependsOn;
            } elseif ('handled_by' === $edge->relation) {
                ++$handledBy;
            }
        }

        $related = [];
        foreach ($graph->nodes() as $node) {
            if (\in_array($node->type, ['route', 'controller'], true)) {
                $related[] = $this->nodeToArray($node);
                if (\count($related) >= $maxItems) {
                    break;
                }
            }
        }

        return new GraphResult(
            summary: \sprintf(
                'Static graph: %d services, %d routes, %d controllers, %d interfaces. %d depends_on edges, %d handled_by edges.',
                $serviceCount,
                $routeCount,
                $controllerCount,
                $interfaceCount,
                $dependsOn,
                $handledBy,
            ),
            primaryNodes: [],
            findings: [
                \sprintf('%d routes mapped to %d controllers.', $routeCount, $controllerCount),
                \sprintf('Container exposes %d services with %d declared interface bindings.', $serviceCount, $interfaceCount),
            ],
            evidence: [],
            relatedNodes: $related,
            nextActions: [
                ['tool' => 'symfony-context', 'args' => ['target' => 'service:<id>']],
                ['tool' => 'symfony-graph', 'args' => ['node' => 'service:<id>', 'depth' => 2]],
            ],
        );
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

    /**
     * @param list<\Symfony\AI\Mate\Bridge\Symfony\Graph\GraphEdge> $edges
     * @param list<array<string, mixed>>                            $related
     */
    private function summarise(GraphNode $node, array $edges, array $related): string
    {
        return \sprintf(
            '%s "%s" with %d related node(s) and %d edge(s) within depth 2.',
            ucfirst($node->type),
            $node->label,
            \count($related),
            \count($edges),
        );
    }

    /**
     * @param list<\Symfony\AI\Mate\Bridge\Symfony\Graph\GraphEdge> $edges
     * @param list<string>                                          $include
     *
     * @return list<string>
     */
    private function collectFindings(GraphNode $node, array $edges, array $include): array
    {
        $findings = [];

        $dependsOnOut = 0;
        $dependsOnIn = 0;
        $handledByOut = 0;
        foreach ($edges as $edge) {
            if ('depends_on' === $edge->relation && $edge->from === $node->id) {
                ++$dependsOnOut;
            }
            if ('depends_on' === $edge->relation && $edge->to === $node->id) {
                ++$dependsOnIn;
            }
            if ('handled_by' === $edge->relation && $edge->from === $node->id) {
                ++$handledByOut;
            }
        }

        if ('service' === $node->type) {
            $findings[] = \sprintf('Has %d direct dependencies and is consumed by %d node(s).', $dependsOnOut, $dependsOnIn);
            if (\in_array('tags', $include, true) && [] !== ($node->metadata['tags'] ?? [])) {
                $findings[] = 'Tagged: '.implode(', ', (array) $node->metadata['tags']).'.';
            }
        }
        if ('route' === $node->type) {
            $methods = $node->metadata['methods'] ?? [];
            $path = $node->metadata['path'] ?? '';
            $findings[] = \sprintf('Route %s %s; handled by %d controller(s).', '' === $path ? '' : $path, [] === $methods ? '(ANY methods)' : '['.implode(',', (array) $methods).']', $handledByOut);
        }

        return $findings;
    }

    /**
     * @return list<array{tool: string, args: array<string, mixed>}>
     */
    private function suggestNextActions(GraphNode $node): array
    {
        return [
            ['tool' => 'symfony-graph', 'args' => ['node' => $node->id, 'depth' => 2]],
            ['tool' => 'symfony-graph', 'args' => ['node' => $node->id, 'depth' => 3, 'relations' => ['depends_on']]],
        ];
    }
}
