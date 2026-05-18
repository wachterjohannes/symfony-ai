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
use Symfony\AI\Mate\Bridge\Symfony\Graph\RequestGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\InspectorRegistry;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * MCP tool that produces a deep dive on a specific node. Routes the request to the static
 * graph (default) or the request-scoped graph (when the node id starts with `request:`),
 * then dispatches to the per-type inspector via {@see InspectorRegistry}.
 *
 * Avoids a `symfony-inspect-service` / `symfony-inspect-route` / `symfony-inspect-request`
 * tool explosion by hiding the dispatch behind a single MCP entry point.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class InspectTool
{
    public function __construct(
        private readonly StaticGraphFactory $staticFactory,
        private readonly ?RequestGraphFactory $requestFactory,
        private readonly InspectorRegistry $registry,
    ) {
    }

    /**
     * @param string       $node    Graph node id (e.g. "service:App\\Service\\Foo",
     *                              "route:app_checkout", "request:<token>").
     * @param list<string> $include Optional hints forwarded to the resolved inspector.
     */
    #[McpTool(
        name: 'symfony-inspect',
        description: 'Deep dive on a specific node (service, route, request). Dispatches to a per-type inspector and returns a tailored GraphResult with findings, evidence, related nodes, and suggested follow-ups.',
    )]
    public function inspect(string $node, array $include = []): string
    {
        if (str_starts_with($node, 'request:')) {
            if (null === $this->requestFactory) {
                return $this->encode($this->profilerUnavailable());
            }
            $token = substr($node, 8);
            $graph = $this->requestFactory->build($token);
            if (null === $graph) {
                return $this->encode($this->unknownToken($token));
            }
        } else {
            $graph = $this->staticFactory->build();
        }

        $graphNode = $graph->node($node);
        if (null === $graphNode) {
            return $this->encode($this->unknownNode($node));
        }

        $inspector = $this->registry->inspectorFor($graphNode);
        if (null === $inspector) {
            return $this->encode($this->noInspector($graphNode->type));
        }

        return $this->encode($inspector->inspect($graphNode, $graph, $include));
    }

    private function profilerUnavailable(): GraphResult
    {
        return new GraphResult(
            summary: 'Request-scoped inspect unavailable — the Symfony profiler is not enabled in the target app.',
            primaryNodes: [],
            findings: [],
            evidence: [],
            relatedNodes: [],
            nextActions: [],
            warnings: ['The profiler bundle (symfony/web-profiler-bundle) must be installed and enabled for request:<token> targets to work.'],
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

    private function unknownNode(string $node): GraphResult
    {
        return new GraphResult(
            summary: \sprintf('No node with id "%s" found in the runtime graph.', $node),
            primaryNodes: [],
            findings: [],
            evidence: [],
            relatedNodes: [],
            nextActions: [],
            warnings: ['Unknown node — list available nodes with symfony-context target=static or symfony-graph.'],
        );
    }

    private function noInspector(string $type): GraphResult
    {
        return new GraphResult(
            summary: \sprintf('No inspector registered for node type "%s".', $type),
            primaryNodes: [],
            findings: [],
            evidence: [],
            relatedNodes: [],
            nextActions: [],
            warnings: [\sprintf('Node type "%s" has no registered inspector — currently supported: service, route, request.', $type)],
        );
    }

    private function encode(GraphResult $result): string
    {
        return ResponseEncoder::encode($result->toArray());
    }
}
