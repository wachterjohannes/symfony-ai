<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Operation\Recipe;

use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphResult;
use Symfony\AI\Mate\Bridge\Symfony\Operation\GraphToolBox;
use Symfony\AI\Mate\Bridge\Symfony\Operation\OperationRecipeInterface;

/**
 * "Why is this request slow?" — composes `symfony-context` on the request, a graph hop on the
 * slowest query, and `symfony-inspect` on the matched route's controller (when present).
 *
 * Default target is `latest_request`; callers can override with `target: "request:<token>"`.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class InvestigatePerformanceRecipe implements OperationRecipeInterface
{
    public function name(): string
    {
        return 'investigate_performance';
    }

    public function description(): string
    {
        return 'Investigate why a request is slow: request context, slowest query hop, controller inspection.';
    }

    public function run(array $args, GraphToolBox $tools): GraphResult
    {
        $target = isset($args['target']) ? (string) $args['target'] : 'latest_request';

        $context = $tools->context($target, ['route', 'controller', 'services', 'queries', 'exceptions'], 30);

        // Early-return if the request envelope itself carries warnings (profiler not loaded,
        // unknown token, etc.) — recipes should propagate the actionable warning instead of
        // pretending to compose further steps on empty data.
        if ([] === $context->primaryNodes) {
            return new GraphResult(
                summary: \sprintf('investigate_performance: %s', $context->summary),
                primaryNodes: [],
                findings: $context->findings,
                evidence: [],
                relatedNodes: [],
                nextActions: [],
                warnings: $context->warnings,
            );
        }

        $findings = $context->findings;
        $evidence = $context->evidence;
        $relatedNodes = $context->relatedNodes;
        $nextActions = $context->nextActions;
        $warnings = $context->warnings;

        $slowestQueryId = $this->pickSlowestQueryId($context);
        if (null !== $slowestQueryId) {
            $subview = $tools->graph($slowestQueryId, 1);
            $findings[] = \sprintf(
                'Expanded slowest query "%s": %d connected node(s).',
                $slowestQueryId,
                \count($subview['nodes']),
            );
            foreach ($subview['edges'] as $edge) {
                $evidence[] = [
                    'kind' => 'edge',
                    'from' => $edge['from'],
                    'relation' => $edge['relation'],
                    'to' => $edge['to'],
                ];
            }
            $nextActions[] = ['tool' => 'symfony-graph', 'args' => ['node' => $slowestQueryId, 'depth' => 2]];
        }

        $routeId = $this->pickRouteId($context);
        if (null !== $routeId) {
            $routeInspect = $tools->inspect($routeId);
            $findings = array_merge($findings, $routeInspect->findings);
            $evidence = array_merge($evidence, $routeInspect->evidence);
            $relatedNodes = array_merge($relatedNodes, $routeInspect->relatedNodes);
            $warnings = array_merge($warnings, $routeInspect->warnings);
            $nextActions[] = ['tool' => 'symfony-inspect', 'args' => ['node' => $routeId]];
        }

        return new GraphResult(
            summary: \sprintf(
                'investigate_performance — %s%s',
                $context->summary,
                null === $slowestQueryId ? '' : ' (slowest query expanded)',
            ),
            primaryNodes: $context->primaryNodes,
            findings: array_values(array_unique($findings)),
            evidence: $evidence,
            relatedNodes: $relatedNodes,
            nextActions: $nextActions,
            warnings: $warnings,
        );
    }

    private function pickSlowestQueryId(GraphResult $context): ?string
    {
        $bestId = null;
        $bestMs = -1.0;
        foreach ($context->relatedNodes as $node) {
            if (($node['type'] ?? null) !== 'query') {
                continue;
            }
            $metadata = $node['metadata'] ?? [];
            $duration = $metadata['total_time_ms'] ?? null;
            if (!\is_int($duration) && !\is_float($duration)) {
                continue;
            }
            if ((float) $duration > $bestMs) {
                $bestMs = (float) $duration;
                $bestId = (string) $node['id'];
            }
        }

        return $bestId;
    }

    private function pickRouteId(GraphResult $context): ?string
    {
        // Prefer the route reachable via matched_route from the request node.
        foreach ($context->evidence as $row) {
            if ('matched_route' === ($row['relation'] ?? null) && \is_string($row['to'] ?? null)) {
                return $row['to'];
            }
        }

        return null;
    }
}
