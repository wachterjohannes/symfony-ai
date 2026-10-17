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

/**
 * Fixture entity that is NOT marked with #[AiEmbeddable].
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class PlainEntity
{
    public function __construct(
        public int $id = 1,
    ) {
    }
}
