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
 * "What blew up in this request?" — composes `symfony-context` filtered to the exception
 * evidence, `symfony-inspect` on the request node for the full envelope, and a `symfony-graph`
 * walk from the exception node back toward the originating service.
 *
 * Default target is `latest_request`; callers can override with `target: "request:<token>"`.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class InvestigateExceptionRecipe implements OperationRecipeInterface
{
    public function name(): string
    {
        return 'investigate_exception';
    }

    public function description(): string
    {
        return 'Investigate the exception raised during a request: context, request inspection, exception graph walk.';
    }

    public function run(array $args, GraphToolBox $tools): GraphResult
    {
        $target = isset($args['target']) ? (string) $args['target'] : 'latest_request';

        $context = $tools->context($target, ['exceptions'], 30);

        if ([] === $context->primaryNodes) {
            return new GraphResult(
                summary: \sprintf('investigate_exception: %s', $context->summary),
                primaryNodes: [],
                findings: $context->findings,
                evidence: [],
                relatedNodes: [],
                nextActions: [],
                warnings: $context->warnings,
            );
        }

        $requestNodeId = (string) $context->primaryNodes[0]['id'];
        $exceptionNodeId = $this->pickExceptionId($context, $requestNodeId);

        if (null === $exceptionNodeId) {
            return new GraphResult(
                summary: \sprintf('investigate_exception — no exception recorded for %s.', $requestNodeId),
                primaryNodes: $context->primaryNodes,
                findings: array_merge($context->findings, ['No exception edge was emitted for this request.']),
                evidence: $context->evidence,
                relatedNodes: $context->relatedNodes,
                nextActions: $context->nextActions,
                warnings: $context->warnings,
            );
        }

        $inspect = $tools->inspect($requestNodeId);
        $subview = $tools->graph($exceptionNodeId, 2);

        $evidence = array_merge($context->evidence, $inspect->evidence);
        foreach ($subview['edges'] as $edge) {
            $evidence[] = [
                'kind' => 'edge',
                'from' => $edge['from'],
                'relation' => $edge['relation'],
                'to' => $edge['to'],
            ];
        }

        $relatedNodes = array_merge($context->relatedNodes, $inspect->relatedNodes);
        $findings = array_merge($context->findings, $inspect->findings, [
            \sprintf('Exception graph hop revealed %d connected node(s).', \count($subview['nodes'])),
        ]);
        $nextActions = array_merge($inspect->nextActions, [
            ['tool' => 'symfony-graph', 'args' => ['node' => $exceptionNodeId, 'depth' => 2]],
        ]);

        return new GraphResult(
            summary: \sprintf('investigate_exception — %s', $context->summary),
            primaryNodes: $context->primaryNodes,
            findings: array_values(array_unique($findings)),
            evidence: $evidence,
            relatedNodes: $relatedNodes,
            nextActions: $nextActions,
            warnings: array_merge($context->warnings, $inspect->warnings),
        );
    }

    private function pickExceptionId(GraphResult $context, string $requestNodeId): ?string
    {
        foreach ($context->evidence as $row) {
            if ('raised_exception' === ($row['relation'] ?? null)
                && ($row['from'] ?? null) === $requestNodeId
                && \is_string($row['to'] ?? null)
            ) {
                return $row['to'];
            }
        }

        return null;
    }
}
