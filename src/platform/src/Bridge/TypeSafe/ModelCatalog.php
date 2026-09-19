<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\TypeSafe;

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ModelCatalog extends AbstractModelCatalog
{
    /**
     * @param array<string, array{class: string, capabilities: list<Capability>}> $additionalModels
     */
    public function __construct(array $additionalModels = [])
    {
        $defaultModels = [
            'jev-latest' => [
                'class' => TypeSafe::class,
                'capabilities' => [
                    Capability::INPUT_TEXT,
                    Capability::CLASSIFICATION,
                ],
            ],
            'jev-preview' => [
                'class' => TypeSafe::class,
                'capabilities' => [
                    Capability::INPUT_TEXT,
                    Capability::CLASSIFICATION,
                ],
            ],
            'jev-1.13.0' => [
                'class' => TypeSafe::class,
                'capabilities' => [
                    Capability::INPUT_TEXT,
                    Capability::CLASSIFICATION,
                ],
            ],
        ];

        $this->models = array_merge($defaultModels, $additionalModels);
    }
}
