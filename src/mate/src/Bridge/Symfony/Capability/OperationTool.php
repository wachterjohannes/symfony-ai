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
use Symfony\AI\Mate\Bridge\Symfony\Operation\GraphToolBox;
use Symfony\AI\Mate\Bridge\Symfony\Operation\RecipeRegistry;
use Symfony\AI\Mate\Encoding\ResponseEncoder;

/**
 * MCP tool that dispatches to a named recipe over the runtime graph tools.
 *
 * Recipes bundle the common multi-step flows agents would otherwise chain by hand into
 * deterministic, named compositions of `symfony-context` / `symfony-graph` / `symfony-inspect`.
 * No new traversal logic lives here — recipes only compose the existing tools.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class OperationTool
{
    public function __construct(
        private readonly RecipeRegistry $registry,
        private readonly GraphToolBox $toolBox,
    ) {
    }

    /**
     * @param string               $operation Recipe name (e.g. `investigate_performance`).
     *                                        When empty or `list`, returns the catalogue of
     *                                        available recipes instead of running anything.
     * @param array<string, mixed> $args      Recipe-specific arguments (see each recipe's docblock).
     */
    #[McpTool(
        name: 'symfony-operation',
        description: 'Run a named, deterministic recipe composed of symfony-context / symfony-graph / symfony-inspect steps. Pass operation="list" to discover available recipes.',
    )]
    public function run(string $operation = '', array $args = []): string
    {
        if ('' === $operation || 'list' === $operation) {
            return $this->encode($this->catalogue());
        }

        $recipe = $this->registry->get($operation);
        if (null === $recipe) {
            return $this->encode($this->unknownRecipe($operation));
        }

        return $this->encode($recipe->run($args, $this->toolBox));
    }

    private function catalogue(): GraphResult
    {
        $recipes = [];
        foreach ($this->registry->all() as $recipe) {
            $recipes[] = [
                'name' => $recipe->name(),
                'description' => $recipe->description(),
            ];
        }

        $findings = [];
        $nextActions = [];
        foreach ($recipes as $entry) {
            $findings[] = \sprintf('%s — %s', $entry['name'], $entry['description']);
            $nextActions[] = ['tool' => 'symfony-operation', 'args' => ['operation' => $entry['name']]];
        }

        return new GraphResult(
            summary: \sprintf('symfony-operation catalogue: %d recipe(s) available.', \count($recipes)),
            primaryNodes: [],
            findings: $findings,
            evidence: [],
            relatedNodes: [],
            nextActions: $nextActions,
        );
    }

    private function unknownRecipe(string $operation): GraphResult
    {
        $known = [];
        foreach ($this->registry->all() as $recipe) {
            $known[] = $recipe->name();
        }

        return new GraphResult(
            summary: \sprintf('Unknown recipe "%s".', $operation),
            primaryNodes: [],
            findings: [],
            evidence: [],
            relatedNodes: [],
            nextActions: [['tool' => 'symfony-operation', 'args' => ['operation' => 'list']]],
            warnings: [\sprintf('Pass operation="list" to discover available recipes. Currently registered: %s.', '' === implode(', ', $known) ? '(none)' : implode(', ', $known))],
        );
    }

    private function encode(GraphResult $result): string
    {
        return ResponseEncoder::encode($result->toArray());
    }
}
