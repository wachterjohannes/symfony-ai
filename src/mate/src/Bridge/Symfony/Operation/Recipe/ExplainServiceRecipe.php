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
 * "Explain this service" — composes `symfony-inspect` with the full include set and a
 * depth-2 `symfony-graph` walk so the response carries both the narrative envelope and the
 * raw structure agents may want to traverse next.
 *
 * Required arg: `service: "App\\Service\\…"` (or any `service:<id>` graph node id).
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ExplainServiceRecipe implements OperationRecipeInterface
{
    public function name(): string
    {
        return 'explain_service';
    }

    public function description(): string
    {
        return 'Explain a service: dependencies, aliases, decorators, tags, plus a depth-2 graph walk.';
    }

    public function run(array $args, GraphToolBox $tools): GraphResult
    {
        $service = isset($args['service']) ? (string) $args['service'] : '';
        if ('' === $service) {
            return new GraphResult(
                summary: 'explain_service: required argument "service" missing.',
                primaryNodes: [],
                findings: [],
                evidence: [],
                relatedNodes: [],
                nextActions: [],
                warnings: ['Provide a "service" argument, e.g. {"service": "service:App\\\\Service\\\\Foo"}.'],
            );
        }

        $nodeId = str_starts_with($service, 'service:') ? $service : 'service:'.$service;

        $inspect = $tools->inspect($nodeId, ['dependencies', 'aliases', 'decorators', 'tags', 'docs']);

        if ([] === $inspect->primaryNodes) {
            return new GraphResult(
                summary: \sprintf('explain_service: %s', $inspect->summary),
                primaryNodes: [],
                findings: $inspect->findings,
                evidence: [],
                relatedNodes: [],
                nextActions: [],
                warnings: $inspect->warnings,
            );
        }

        $subview = $tools->graph($nodeId, 2);

        $evidence = $inspect->evidence;
        foreach ($subview['edges'] as $edge) {
            $evidence[] = [
                'kind' => 'edge',
                'from' => $edge['from'],
                'relation' => $edge['relation'],
                'to' => $edge['to'],
            ];
        }

        $nextActions = $inspect->nextActions;
        foreach (\array_slice($subview['suggestedFocus'], 0, 3) as $focusId) {
            $nextActions[] = ['tool' => 'symfony-context', 'args' => ['target' => $focusId]];
        }

        return new GraphResult(
            summary: \sprintf('explain_service — %s', $inspect->summary),
            primaryNodes: $inspect->primaryNodes,
            findings: $inspect->findings,
            evidence: $evidence,
            relatedNodes: $inspect->relatedNodes,
            nextActions: $nextActions,
            warnings: $inspect->warnings,
        );
    }
}
