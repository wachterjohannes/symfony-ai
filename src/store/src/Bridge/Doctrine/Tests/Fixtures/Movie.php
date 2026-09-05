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

/**
 * Template-rendered fixture entity.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[AiEmbeddable(template: 'The movie "{title}" was released in {year}.', title: 'title')]
class Movie
{
    public function __construct(
        public int $id,
        public string $title,
        public int $year,
    ) {
    }
}
