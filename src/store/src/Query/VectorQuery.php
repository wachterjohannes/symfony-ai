<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Query;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Query\Filter\FilterInterface;

/**
 * Classic vector search query using semantic similarity.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class VectorQuery implements QueryInterface
{
    public function __construct(
        private readonly Vector $vector,
        private readonly ?FilterInterface $filter = null,
    ) {
    }

    public function getVector(): Vector
    {
        return $this->vector;
    }

    public function getFilter(): ?FilterInterface
    {
        return $this->filter;
    }
}
