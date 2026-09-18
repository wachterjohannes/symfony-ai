<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\TypeSafe\Tests;

use Symfony\AI\Platform\Bridge\TypeSafe\ModelCatalog;
use Symfony\AI\Platform\Bridge\TypeSafe\TypeSafe;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\Test\ModelCatalogTestCase;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ModelCatalogTest extends ModelCatalogTestCase
{
    public static function modelsProvider(): iterable
    {
        yield 'jev' => ['jev', TypeSafe::class, [Capability::INPUT_TEXT, Capability::CLASSIFICATION]];
    }

    protected function createModelCatalog(): ModelCatalogInterface
    {
        return new ModelCatalog();
    }
}
