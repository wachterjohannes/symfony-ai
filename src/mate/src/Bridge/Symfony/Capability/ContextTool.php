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
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphEdge;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphView;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RequestGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * MCP tool that returns a compact, connected runtime view (GraphResult envelope) for a
 * requested target.
 *
 * Supported targets: `static`, `route:<name>`, `service:<id>`, `controller:<fqcn>::<method>`,
 * `interface:<fqcn>`, `request:<token>`, `latest_request` (alias `request`). Request-scoped
 * targets require the profiler to be enabled in the host app — when unavailable, the tool
 * returns a degradation envelope rather than throwing.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 *
 * @phpstan-type EdgeStats array{outgoing: array<string, int>, incoming: array<string, int>}
 */
class ContextTool
{
    /**
     * Neighbourhood depth used when a specific node is targeted (service, route, controller,
     * interface). Kept opinionated on purpose — callers who want a different depth should
     * reach for {@see GraphTool} instead. Does not apply to the `static` overview, which lists
     * top-level nodes without traversing.
     */
    private const NODE_NEIGHBOURHOOD_DEPTH = 2;

    /**
     * Deeper hop suggested in {@see GraphResult::$nextActions} when the caller wants to
     * follow a chain (e.g. transitive `depends_on`).
     */
    private const FOLLOW_UP_DEPTH = 3;

    public function __construct(
        private readonly StaticGraphFactory $factory,
        private readonly ?RequestGraphFactory $requestFactory = null,
        private readonly ?ProfilerDataProvider $profiler = null,
    ) {
    }

    /**
     * @param string       $target   One of: 'static', 'service:<id>', 'route:<name>',
     *                               'controller:<fqcn>::<method>', 'interface:<fqcn>',
     *                               'request:<token>', 'latest_request' (alias 'request').
     * @param list<string> $include  Hints which neighbour categories matter most (advisory;
     *                               currently consulted for `tags` on service targets).
     * @param int          $maxItems Soft cap on the size of the returned neighbourhood.
     */
    #[McpTool(
        name: 'symfony-context',
        description: 'Return a compact, connected runtime view for a target node ("service:Foo", "route:app_checkout", "request:<token>", "latest_request", or "static" for an app-wide summary). Bundles primary nodes, related nodes, evidence edges, and suggested follow-up tools in a single response.',
    )]
    public function getContext(
        string $target = 'static',
        array $include = ['route', 'controller', 'services'],
        int $maxItems = 20,
    ): string {
        if ('' === $target) {
            return $this->encode($this->unknownTarget($target));
        }

        // Resolve `latest_request` / bare `request` to a concrete `request:<token>` before
        // dispatching, so the request branch only needs to handle one shape.
        if ('latest_request' === $target || 'request' === $target) {
            if (null === $this->profiler) {
                return $this->encode($this->profilerUnavailable());
            }
            $latest = $this->profiler->getLatestProfile();
            if (null === $latest) {
                return $this->encode($this->noProfiles());
            }
            $target = 'request:'.$latest->getToken();
        }

        if (str_starts_with($target, 'request:')) {
            if (null === $this->requestFactory) {
                return $this->encode($this->profilerUnavailable());
            }
            $token = substr($target, 8);
            $graph = $this->requestFactory->build($token);
            if (null === $graph) {
                return $this->encode($this->unknownToken($token));
            }
            $node = $graph->node('request:'.$token);
            if (null === $node) {
                return $this->encode($this->unknownTarget($target));
            }

            return $this->encode($this->envelopeForNode($node, $graph, $include, $maxItems));
        }

        $graph = $this->factory->build();

        if ('static' === $target) {
            return $this->encode($this->staticOverview($graph, $maxItems));
        }

        $node = $graph->node($target);
        if (null === $node) {
            return $this->encode($this->unknownTarget($target));
        }

        return $this->encode($this->envelopeForNode($node, $graph, $include, $maxItems));
    }

    /**
     * Assembles the neighbourhood envelope around a single node. Shared by service / route /
     * controller / interface / request targets — only the per-type summary/findings/nextActions
     * branches differ, and they're handled inside the helpers themselves via the node's `type`.
     *
     * @param list<string> $include
     */
    private function envelopeForNode(GraphNode $node, RuntimeGraph $graph, array $include, int $maxItems): GraphResult
    {
        $view = $graph->neighbors($node->id, self::NODE_NEIGHBOURHOOD_DEPTH, [], $maxItems);
        $stats = $this->edgeStats($node, $view->edges);

        return new GraphResult(
            summary: $this->summary($node, $view, $stats),
            primaryNodes: [$this->nodeToArray($node)],
            findings: $this->findings($node, $stats, $include, $graph, $view),
            evidence: $this->evidence($view->edges),
            relatedNodes: $this->relatedNodes($node, $view),
            nextActions: $this->nextActions($node),
            warnings: $view->truncated
                ? [\sprintf('Neighbourhood truncated at maxItems=%d; raise maxItems for a wider view.', $maxItems)]
                : [],
        );
    }

    /**
     * @param list<GraphEdge> $edges
     *
     * @phpstan-return EdgeStats
     */
    private function edgeStats(GraphNode $node, array $edges): array
    {
        $outgoing = [];
        $incoming = [];

        foreach ($edges as $edge) {
            if ($edge->from === $node->id) {
                $outgoing[$edge->relation] = ($outgoing[$edge->relation] ?? 0) + 1;
            }
            if ($edge->to === $node->id) {
                $incoming[$edge->relation] = ($incoming[$edge->relation] ?? 0) + 1;
            }
        }

        return ['outgoing' => $outgoing, 'incoming' => $incoming];
    }

    /**
     * @phpstan-param EdgeStats $stats
     */
    private function summary(GraphNode $node, GraphView $view, array $stats): string
    {
        return match ($node->type) {
            'service' => $this->serviceSummary($node, $stats),
            'route' => $this->routeSummary($node, $stats),
            'controller' => $this->controllerSummary($node, $stats),
            'interface' => $this->interfaceSummary($node, $stats),
            'request' => $this->requestSummary($node, $stats),
            default => $this->genericSummary($node, $view),
        };
    }

    /**
     * @phpstan-param EdgeStats $stats
     */
    private function requestSummary(GraphNode $node, array $stats): string
    {
        $method = (string) ($node->metadata['method'] ?? '');
        $url = (string) ($node->metadata['url'] ?? '');
        $statusCode = $node->metadata['status_code'] ?? null;
        $durationMs = $node->metadata['duration_ms'] ?? null;
        $queryCount = $stats['outgoing']['executed_query'] ?? 0;

        $head = trim(\sprintf('%s %s', $method, $url));
        if (null !== $statusCode) {
            $head .= ' → '.$statusCode;
        }
        if (null !== $durationMs) {
            $head .= \sprintf(' (%s ms)', $durationMs);
        }

        return \sprintf(
            'Request %s executed %s.',
            '' === $head ? $node->label : $head,
            $this->plural((int) $queryCount, 'database query', 'database queries'),
        );
    }

    /**
     * @phpstan-param EdgeStats $stats
     */
    private function serviceSummary(GraphNode $node, array $stats): string
    {
        return \sprintf(
            'Service %s has %s and is consumed by %s.',
            $node->label,
            $this->plural($stats['outgoing']['depends_on'] ?? 0, 'direct dependency', 'direct dependencies'),
            $this->plural($stats['incoming']['depends_on'] ?? 0, 'consumer', 'consumers'),
        );
    }

    /**
     * @phpstan-param EdgeStats $stats
     */
    private function routeSummary(GraphNode $node, array $stats): string
    {
        $methods = $this->methodLabel((array) ($node->metadata['methods'] ?? []));
        $path = (string) ($node->metadata['path'] ?? '/');

        return \sprintf(
            'Route %s (%s %s) is handled by %s.',
            $node->label,
            $methods,
            $path,
            $this->plural($stats['outgoing']['handled_by'] ?? 0, 'controller', 'controllers'),
        );
    }

    /**
     * @phpstan-param EdgeStats $stats
     */
    private function controllerSummary(GraphNode $node, array $stats): string
    {
        return \sprintf(
            'Controller %s is wired to %s and depends on %s.',
            $node->label,
            $this->plural($stats['incoming']['handled_by'] ?? 0, 'route', 'routes'),
            $this->plural($stats['outgoing']['depends_on'] ?? 0, 'service', 'services'),
        );
    }

    /**
     * @phpstan-param EdgeStats $stats
     */
    private function interfaceSummary(GraphNode $node, array $stats): string
    {
        return \sprintf(
            'Interface %s is implemented by %s.',
            $node->label,
            $this->plural($stats['outgoing']['implemented_by'] ?? 0, 'service', 'services'),
        );
    }

    private function genericSummary(GraphNode $node, GraphView $view): string
    {
        $related = max(0, \count($view->nodes) - 1);

        return \sprintf(
            'Node %s (%s): %s and %s within depth %d.',
            $node->label,
            $node->type,
            $this->plural($related, 'related node', 'related nodes'),
            $this->plural(\count($view->edges), 'edge', 'edges'),
            self::NODE_NEIGHBOURHOOD_DEPTH,
        );
    }

    /**
     * @phpstan-param EdgeStats $stats
     * @param list<string>     $include
     *
     * @return list<string>
     */
    private function findings(GraphNode $node, array $stats, array $include, RuntimeGraph $graph, GraphView $view): array
    {
        $findings = [];

        if ('service' === $node->type) {
            $tags = (array) ($node->metadata['tags'] ?? []);
            if ([] !== $tags && \in_array('tags', $include, true)) {
                $findings[] = 'Tags: '.implode(', ', array_map(static fn ($t) => (string) $t, $tags)).'.';
            }

            $aliasOf = $node->metadata['aliasOf'] ?? null;
            if (\is_string($aliasOf) && '' !== $aliasOf) {
                $findings[] = \sprintf('Alias of "%s".', $aliasOf);
            }
        }

        if ('route' === $node->type) {
            $methods = (array) ($node->metadata['methods'] ?? []);
            if ([] === $methods) {
                $findings[] = 'No HTTP method restriction — matches every verb.';
            }
        }

        if ('controller' === $node->type && 0 === ($stats['outgoing']['depends_on'] ?? 0)) {
            $findings[] = 'Controller has no matching service definition in the container — likely autowired without an explicit registration.';
        }

        if ('request' === $node->type) {
            $findings = array_merge($findings, $this->requestFindings($node, $graph, $view));
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    private function requestFindings(GraphNode $node, RuntimeGraph $graph, GraphView $view): array
    {
        $findings = [];

        $slowest = null;
        $slowestMs = -1.0;
        $queryCount = 0;
        $exceptionClass = null;
        foreach ($view->edges as $edge) {
            if ($edge->from !== $node->id) {
                continue;
            }
            if ('executed_query' === $edge->relation) {
                ++$queryCount;
                $queryNode = $graph->node($edge->to);
                if (null === $queryNode) {
                    continue;
                }
                $duration = $queryNode->metadata['total_time_ms'] ?? null;
                if (\is_int($duration) || \is_float($duration)) {
                    $durationFloat = (float) $duration;
                    if ($durationFloat > $slowestMs) {
                        $slowestMs = $durationFloat;
                        $slowest = $queryNode;
                    }
                }
            }
            if ('raised_exception' === $edge->relation) {
                $exceptionNode = $graph->node($edge->to);
                if (null !== $exceptionNode) {
                    $exceptionClass = (string) ($exceptionNode->metadata['class'] ?? $exceptionNode->label);
                }
            }
        }

        if (0 === $queryCount) {
            $findings[] = 'No database queries recorded for this request.';
        } elseif (null !== $slowest) {
            $sql = (string) ($slowest->metadata['sql'] ?? $slowest->label);
            $findings[] = \sprintf(
                'Slowest query: %s (%s ms).',
                mb_strlen($sql) > 100 ? mb_substr($sql, 0, 100).'…' : $sql,
                $slowestMs,
            );
        }

        if (null !== $exceptionClass) {
            $findings[] = \sprintf('Raised exception: %s.', $exceptionClass);
        }

        return $findings;
    }

    /**
     * @param list<GraphEdge> $edges
     *
     * @return list<array{kind: string, from: string, relation: string, to: string}>
     */
    private function evidence(array $edges): array
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
     * @return list<array{id: string, type: string, label: string, metadata: array<string, mixed>}>
     */
    private function relatedNodes(GraphNode $primary, GraphView $view): array
    {
        $related = [];
        foreach ($view->nodes as $other) {
            if ($other->id === $primary->id) {
                continue;
            }
            $related[] = $this->nodeToArray($other);
        }

        return $related;
    }

    /**
     * @return list<array{tool: string, args: array<string, mixed>}>
     */
    private function nextActions(GraphNode $node): array
    {
        if ('request' === $node->type) {
            $actions = [
                ['tool' => 'symfony-inspect', 'args' => ['node' => $node->id]],
            ];
            $route = $node->metadata['route'] ?? null;
            if (\is_string($route) && '' !== $route) {
                $actions[] = ['tool' => 'symfony-context', 'args' => ['target' => 'route:'.$route]];
            }

            return $actions;
        }

        return [
            ['tool' => 'symfony-inspect', 'args' => ['node' => $node->id]],
            ['tool' => 'symfony-graph', 'args' => ['node' => $node->id, 'depth' => self::NODE_NEIGHBOURHOOD_DEPTH]],
            ['tool' => 'symfony-graph', 'args' => ['node' => $node->id, 'depth' => self::FOLLOW_UP_DEPTH, 'relations' => ['depends_on']]],
        ];
    }

    private function staticOverview(RuntimeGraph $graph, int $maxItems): GraphResult
    {
        $nodeCounts = ['service' => 0, 'route' => 0, 'controller' => 0, 'interface' => 0];
        foreach ($graph->nodes() as $node) {
            if (isset($nodeCounts[$node->type])) {
                ++$nodeCounts[$node->type];
            }
        }

        $edgeCounts = ['depends_on' => 0, 'handled_by' => 0, 'implemented_by' => 0];
        foreach ($graph->edges() as $edge) {
            if (isset($edgeCounts[$edge->relation])) {
                ++$edgeCounts[$edge->relation];
            }
        }

        $related = [];
        foreach ($graph->nodes() as $node) {
            if (!\in_array($node->type, ['route', 'controller'], true)) {
                continue;
            }
            $related[] = $this->nodeToArray($node);
            if (\count($related) >= $maxItems) {
                break;
            }
        }

        $sampleServiceId = $this->topDegreeServiceId($graph);
        $nextActions = [];
        if (null !== $sampleServiceId) {
            $nextActions[] = ['tool' => 'symfony-context', 'args' => ['target' => $sampleServiceId]];
            $nextActions[] = ['tool' => 'symfony-graph', 'args' => ['node' => $sampleServiceId, 'depth' => self::NODE_NEIGHBOURHOOD_DEPTH]];
        }

        return new GraphResult(
            summary: \sprintf(
                'Static graph: %s, %s, %s, %s.',
                $this->plural($nodeCounts['service'], 'service', 'services'),
                $this->plural($nodeCounts['route'], 'route', 'routes'),
                $this->plural($nodeCounts['controller'], 'controller', 'controllers'),
                $this->plural($nodeCounts['interface'], 'interface', 'interfaces'),
            ),
            primaryNodes: [],
            findings: [
                \sprintf(
                    '%s mapped to %s via handled_by edges.',
                    $this->plural($nodeCounts['route'], 'route', 'routes'),
                    $this->plural($edgeCounts['handled_by'], 'controller call', 'controller calls'),
                ),
                \sprintf(
                    'Container exposes %s with %s declared between them.',
                    $this->plural($nodeCounts['service'], 'service', 'services'),
                    $this->plural($edgeCounts['depends_on'], 'dependency', 'dependencies'),
                ),
            ],
            evidence: [],
            relatedNodes: $related,
            nextActions: $nextActions,
        );
    }

    /**
     * Picks the service node with the highest depends_on out-degree as a representative target
     * for the `nextActions` hints in the static overview. Returns null when the graph holds no
     * service nodes (in which case the hints are omitted entirely rather than referencing a
     * non-existent id).
     */
    private function topDegreeServiceId(RuntimeGraph $graph): ?string
    {
        $outDegree = [];
        foreach ($graph->edges() as $edge) {
            if ('depends_on' !== $edge->relation) {
                continue;
            }
            $from = $graph->node($edge->from);
            if (null === $from || 'service' !== $from->type) {
                continue;
            }
            $outDegree[$edge->from] = ($outDegree[$edge->from] ?? 0) + 1;
        }

        if ([] !== $outDegree) {
            arsort($outDegree);

            return (string) array_key_first($outDegree);
        }

        // Fall back to the first service node so callers still get an actionable hint when no
        // service has dependencies wired (e.g. tiny fixture containers).
        foreach ($graph->nodes() as $node) {
            if ('service' === $node->type) {
                return $node->id;
            }
        }

        return null;
    }

    private function profilerUnavailable(): GraphResult
    {
        return new GraphResult(
            summary: 'Request-scoped context unavailable — the Symfony profiler is not enabled in the target app.',
            primaryNodes: [],
            findings: [],
            evidence: [],
            relatedNodes: [],
            nextActions: [],
            warnings: ['The profiler bundle (symfony/web-profiler-bundle) must be installed and enabled for request-scoped targets (latest_request, request:<token>) to work.'],
        );
    }

    private function noProfiles(): GraphResult
    {
        return new GraphResult(
            summary: 'No profiler entries found — make a request against the target app first.',
            primaryNodes: [],
            findings: [],
            evidence: [],
            relatedNodes: [],
            nextActions: [],
            warnings: ['The profiler store is empty. Hit any route in the target Symfony app to seed the first profile.'],
        );
    }

    private function unknownToken(string $token): GraphResult
    {
        return new GraphResult(
            summary: \sprintf('No profile found for token "%s".', $token),
            primaryNodes: [],
            findings: [],
            evidence: [],
            relatedNodes: [],
            nextActions: [],
            warnings: ['Unknown profiler token — list available tokens with the symfony-profiler-list tool.'],
        );
    }

    private function unknownTarget(string $target): GraphResult
    {
        return new GraphResult(
            summary: \sprintf('No node with id "%s" found in the runtime graph.', $target),
            primaryNodes: [],
            findings: [],
            evidence: [],
            relatedNodes: [],
            nextActions: [],
            warnings: ['Unknown target — make sure the target Symfony app has built its container cache and (for routes) warmed its router.'],
        );
    }

    /**
     * @param list<string> $methods
     */
    private function methodLabel(array $methods): string
    {
        return [] === $methods ? 'ANY' : implode('|', $methods);
    }

    private function plural(int $count, string $singular, string $plural): string
    {
        return $count.' '.(1 === $count ? $singular : $plural);
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

    private function encode(GraphResult $result): string
    {
        return ResponseEncoder::encode($result->toArray());
    }
}
