<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\GraphProvider;

use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphContext;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraphBuilder;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Exception\InvalidCollectorException;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Exception\ProfileNotFoundException;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;

/**
 * Contributes request-scoped nodes and edges to the runtime graph for a given profiler token.
 *
 * Activated only when {@see GraphContext::$profilerToken} is set; on the static graph build
 * path this provider no-ops silently. The profiler token addresses one HTTP request, and the
 * provider emits:
 *  - one `request:<token>` node with method/url/status/duration metadata,
 *  - `(request) --matched_route--> (route:<name>)` when the static graph already carries a
 *    matching `route:<name>` node (soft edge, mirroring {@see \Symfony\AI\Mate\Bridge\Symfony\Graph\ControllerServiceLinker}),
 *  - `(request) --executed_query--> (query:<token>:<index>)` per Doctrine query,
 *  - `(request) --raised_exception--> (exception:<class>)` when the exception collector reports one.
 *
 * Twig (`rendered_template`) and Monolog (`logged`) edges are deliberately deferred — they
 * require collector formatters that don't yet exist in the bridge.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ProfilerGraphProvider implements GraphProviderInterface
{
    public function __construct(
        private readonly ProfilerDataProvider $profiler,
    ) {
    }

    public function populate(RuntimeGraphBuilder $graph, GraphContext $context): void
    {
        $token = $context->profilerToken;
        if (null === $token) {
            return;
        }

        try {
            $profileData = $this->profiler->findProfile($token);
        } catch (ProfileNotFoundException) {
            return;
        }
        if (null === $profileData) {
            return;
        }

        $requestSummary = $this->collectorSummary($token, 'request');
        $timeSummary = $this->collectorSummary($token, 'time');

        $this->emitRequestNode($graph, $token, $requestSummary, $timeSummary);
        $this->emitMatchedRouteEdge($graph, $token, $requestSummary);
        $this->emitQueryEdges($graph, $token);
        $this->emitExceptionEdge($graph, $token);
        // TODO: rendered_template (Twig) + logged (Monolog) edges deferred — needs collector formatters first.
    }

    /**
     * @param array<string, mixed> $requestSummary
     * @param array<string, mixed> $timeSummary
     */
    private function emitRequestNode(RuntimeGraphBuilder $graph, string $token, array $requestSummary, array $timeSummary): void
    {
        $method = isset($requestSummary['method']) ? (string) $requestSummary['method'] : '';
        $path = isset($requestSummary['path']) ? (string) $requestSummary['path'] : '';
        $statusCode = $requestSummary['status_code'] ?? null;
        $route = isset($requestSummary['route']) ? (string) $requestSummary['route'] : null;
        $durationMs = $timeSummary['duration_ms'] ?? null;

        $label = trim(\sprintf('%s %s', $method, $path));
        if (null !== $statusCode) {
            $label = trim($label).' → '.$statusCode;
        }
        if ('' === $label) {
            $label = 'request:'.$token;
        }

        $metadata = [
            'token' => $token,
            'method' => $method,
            'url' => $path,
            'status_code' => $statusCode,
        ];
        if (null !== $route && '' !== $route) {
            $metadata['route'] = $route;
        }
        if (null !== $durationMs) {
            $metadata['duration_ms'] = $durationMs;
        }

        $graph->node('request:'.$token, 'request', $label, $metadata);
    }

    /**
     * @param array<string, mixed> $requestSummary
     */
    private function emitMatchedRouteEdge(RuntimeGraphBuilder $graph, string $token, array $requestSummary): void
    {
        $route = isset($requestSummary['route']) ? (string) $requestSummary['route'] : '';
        if ('' === $route) {
            return;
        }
        $routeNodeId = 'route:'.$route;
        if (!$graph->has($routeNodeId)) {
            // Soft edge: skip silently when the static graph doesn't know about this route.
            return;
        }
        $graph->edge('request:'.$token, 'matched_route', $routeNodeId);
    }

    private function emitQueryEdges(RuntimeGraphBuilder $graph, string $token): void
    {
        $data = $this->collectorData($token, 'db');
        $queries = $data['queries'] ?? [];
        if (!\is_array($queries)) {
            return;
        }

        foreach ($queries as $index => $query) {
            if (!\is_array($query)) {
                continue;
            }
            $sql = isset($query['sql']) ? (string) $query['sql'] : '';
            $queryNodeId = 'query:'.$token.':'.$index;

            $graph->node(
                $queryNodeId,
                'query',
                '' === $sql ? $queryNodeId : mb_substr($sql, 0, 80),
                [
                    'sql' => $sql,
                    'count' => $query['count'] ?? null,
                    'total_time_ms' => $query['total_time_ms'] ?? null,
                    'avg_time_ms' => $query['avg_time_ms'] ?? null,
                    'sample_params' => $query['sample_params'] ?? null,
                ],
            );
            $graph->edge('request:'.$token, 'executed_query', $queryNodeId);
        }
    }

    private function emitExceptionEdge(RuntimeGraphBuilder $graph, string $token): void
    {
        $data = $this->collectorData($token, 'exception');
        if (true !== ($data['has_exception'] ?? false)) {
            return;
        }

        $class = isset($data['class']) ? (string) $data['class'] : '';
        if ('' === $class) {
            return;
        }

        // Node id is class-keyed per the issue spec. Within a single request's slice this is
        // fine (one exception per request), and each token gets its own cached graph file so
        // cross-request collisions never materialise.
        $exceptionNodeId = 'exception:'.$class;
        $graph->node($exceptionNodeId, 'exception', $class, [
            'class' => $class,
            'message' => $data['message'] ?? null,
            'status_code' => $data['status_code'] ?? null,
            'file' => $data['file'] ?? null,
            'line' => $data['line'] ?? null,
        ]);
        $graph->edge('request:'.$token, 'raised_exception', $exceptionNodeId);
    }

    /**
     * @return array<string, mixed>
     */
    private function collectorSummary(string $token, string $collectorName): array
    {
        try {
            $payload = $this->profiler->getCollectorData($token, $collectorName);
        } catch (ProfileNotFoundException|InvalidCollectorException) {
            return [];
        }

        return \is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectorData(string $token, string $collectorName): array
    {
        try {
            $payload = $this->profiler->getCollectorData($token, $collectorName);
        } catch (ProfileNotFoundException|InvalidCollectorException) {
            return [];
        }

        return \is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }
}
