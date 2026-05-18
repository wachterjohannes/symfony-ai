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
 * Request-specific deep dive. Surfaces request line, status, duration, top-N slowest queries,
 * raised exception (if any), and the matched route.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RequestInspector implements InspectorInterface
{
    private const NEIGHBOURHOOD_DEPTH = 1;
    private const TOP_QUERIES = 3;

    public function supports(GraphNode $node): bool
    {
        return 'request' === $node->type;
    }

    public function inspect(GraphNode $node, RuntimeGraph $graph, array $include): GraphResult
    {
        $view = $graph->neighbors($node->id, self::NEIGHBOURHOOD_DEPTH);

        $primary = [$this->nodeToArray($node)];
        $relatedRoute = null;
        $queryNodes = [];
        $exceptionNode = null;

        foreach ($view->edges as $edge) {
            if ($edge->from !== $node->id) {
                continue;
            }
            $target = $graph->node($edge->to);
            if (null === $target) {
                continue;
            }
            if ('matched_route' === $edge->relation) {
                $relatedRoute = $target;
                $primary[] = $this->nodeToArray($target);
            } elseif ('executed_query' === $edge->relation) {
                $queryNodes[] = $target;
            } elseif ('raised_exception' === $edge->relation) {
                $exceptionNode = $target;
            }
        }

        usort(
            $queryNodes,
            static function (GraphNode $a, GraphNode $b): int {
                $da = (float) ($a->metadata['total_time_ms'] ?? 0.0);
                $db = (float) ($b->metadata['total_time_ms'] ?? 0.0);

                return $db <=> $da;
            },
        );

        $method = (string) ($node->metadata['method'] ?? '');
        $url = (string) ($node->metadata['url'] ?? '');
        $statusCode = $node->metadata['status_code'] ?? null;
        $durationMs = $node->metadata['duration_ms'] ?? null;

        $findings = [
            trim(\sprintf('%s %s%s%s', $method, $url, null !== $statusCode ? ' → '.$statusCode : '', null !== $durationMs ? ' ('.$durationMs.' ms)' : '')),
        ];

        $topQueries = \array_slice($queryNodes, 0, self::TOP_QUERIES);
        if ([] === $topQueries) {
            $findings[] = 'No database queries recorded.';
        } else {
            foreach ($topQueries as $rank => $query) {
                $sql = (string) ($query->metadata['sql'] ?? $query->label);
                $duration = $query->metadata['total_time_ms'] ?? 'n/a';
                $findings[] = \sprintf(
                    'Query #%d (%s ms): %s',
                    $rank + 1,
                    $duration,
                    mb_strlen($sql) > 120 ? mb_substr($sql, 0, 120).'…' : $sql,
                );
            }
        }

        if (null !== $exceptionNode) {
            $class = (string) ($exceptionNode->metadata['class'] ?? $exceptionNode->label);
            $message = (string) ($exceptionNode->metadata['message'] ?? '');
            $findings[] = \sprintf(
                'Raised exception: %s%s',
                $class,
                '' === $message ? '' : ' — '.$message,
            );
        }

        if (null !== $relatedRoute) {
            $findings[] = \sprintf('Matched route: %s', $relatedRoute->label);
        }

        $nextActions = [];
        if (null !== $relatedRoute) {
            $nextActions[] = ['tool' => 'symfony-context', 'args' => ['target' => $relatedRoute->id]];
        }
        $nextActions[] = ['tool' => 'symfony-graph', 'args' => ['node' => $node->id, 'depth' => 2]];

        return new GraphResult(
            summary: \sprintf(
                'Request %s%s executed %d quer%s.',
                trim(\sprintf('%s %s', $method, $url)) ?: $node->label,
                null !== $statusCode ? ' → '.$statusCode : '',
                \count($queryNodes),
                1 === \count($queryNodes) ? 'y' : 'ies',
            ),
            primaryNodes: $primary,
            findings: $findings,
            evidence: $this->edgesToEvidence($view->edges),
            relatedNodes: $this->relatedNodesExcluding($view->nodes, $node->id),
            nextActions: $nextActions,
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
