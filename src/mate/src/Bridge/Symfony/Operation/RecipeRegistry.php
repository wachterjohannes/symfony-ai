<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Operation;

/**
 * Resolves a recipe by name. Recipes are collected via the `ai_mate.graph_operation_recipe`
 * DI tag; iteration order is deterministic across builds since the tagged iterator preserves
 * registration order.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RecipeRegistry
{
    /**
     * @var array<string, OperationRecipeInterface>|null
     */
    private ?array $byName = null;

    /**
     * @param iterable<OperationRecipeInterface> $recipes
     */
    public function __construct(
        private readonly iterable $recipes,
    ) {
    }

    public function get(string $name): ?OperationRecipeInterface
    {
        return $this->indexed()[$name] ?? null;
    }

    /**
     * @return list<OperationRecipeInterface>
     */
    public function all(): array
    {
        return array_values($this->indexed());
    }

    /**
     * @return array<string, OperationRecipeInterface>
     */
    private function indexed(): array
    {
        if (null !== $this->byName) {
            return $this->byName;
        }

        $byName = [];
        foreach ($this->recipes as $recipe) {
            $byName[$recipe->name()] = $recipe;
        }

        return $this->byName = $byName;
    }
}
