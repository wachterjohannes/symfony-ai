<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Query\Filter;

/**
 * Base interface for query filters.
 *
 * Filters define constraints on document metadata to narrow search results.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface FilterInterface
{
    public function getType(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
