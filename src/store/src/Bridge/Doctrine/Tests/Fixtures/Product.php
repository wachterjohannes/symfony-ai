<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Doctrine\Tests\Fixtures;

use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddable;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddableField;

/**
 * Field-rendered fixture entity. The password hash is intentionally NOT marked embeddable.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[AiEmbeddable(title: 'name')]
class Product
{
    public function __construct(
        public int $id,
        #[AiEmbeddableField]
        public string $name,
        #[AiEmbeddableField(label: 'Price in EUR')]
        public float $price,
        public string $passwordHash = 'secret-hash',
    ) {
    }
}
