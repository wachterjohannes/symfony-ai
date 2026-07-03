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
 * ORM-mapped fixture entity whose identifier is an association (derived identity), used to verify that
 * identity metadata is flattened to the scalar identifier value of the associated entity.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[ORM\Entity]
#[AiEmbeddable]
class AuthorProfile
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: Author::class)]
        public Author $author,
        #[ORM\Column(type: 'string')]
        #[AiEmbeddableField]
        public string $biography,
    ) {
    }
}
