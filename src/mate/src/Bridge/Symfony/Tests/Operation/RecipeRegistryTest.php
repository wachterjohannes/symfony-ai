<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Operation;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphResult;
use Symfony\AI\Mate\Bridge\Symfony\Operation\GraphToolBox;
use Symfony\AI\Mate\Bridge\Symfony\Operation\OperationRecipeInterface;
use Symfony\AI\Mate\Bridge\Symfony\Operation\RecipeRegistry;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RecipeRegistryTest extends TestCase
{
    public function testReturnsNullForUnknownRecipe()
    {
        $registry = new RecipeRegistry([]);

        $this->assertNull($registry->get('does-not-exist'));
        $this->assertSame([], $registry->all());
    }

    public function testResolvesByName()
    {
        $recipe = $this->stubRecipe('foo');
        $registry = new RecipeRegistry([$recipe]);

        $this->assertSame($recipe, $registry->get('foo'));
        $this->assertCount(1, $registry->all());
    }

    public function testIndexedLazilyAndOnceForIterableSources()
    {
        $recipe = $this->stubRecipe('bar');
        $calls = 0;
        $generator = function () use ($recipe, &$calls) {
            ++$calls;
            yield $recipe;
        };

        $registry = new RecipeRegistry($generator());

        $this->assertSame($recipe, $registry->get('bar'));
        $this->assertSame($recipe, $registry->get('bar'));
        $this->assertSame(1, $calls, 'Generator must only be iterated once.');
    }

    private function stubRecipe(string $name): OperationRecipeInterface
    {
        return new class($name) implements OperationRecipeInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function description(): string
            {
                return 'stub';
            }

            public function run(array $args, GraphToolBox $tools): GraphResult
            {
                return new GraphResult('stub', [], [], [], [], []);
            }
        };
    }
}
