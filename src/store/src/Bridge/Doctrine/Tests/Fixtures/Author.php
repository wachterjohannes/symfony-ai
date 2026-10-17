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

use Doctrine\ORM\Mapping as ORM;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddable;
use Symfony\AI\Store\Bridge\Doctrine\Attribute\AiEmbeddableField;

/**
 * Second ORM-mapped fixture entity, used to test hydration of mixed-class result sets.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[ORM\Entity]
#[AiEmbeddable(title: 'name')]
class Author
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'integer')]
        public int $id,
        #[ORM\Column(type: 'string')]
        #[AiEmbeddableField]
        public string $name,
    ) {
    }
}
