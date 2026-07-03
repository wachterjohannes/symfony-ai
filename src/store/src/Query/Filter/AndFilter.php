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

use Symfony\AI\Store\Exception\InvalidArgumentException;

/**
 * Matches documents that satisfy all of the given filters.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class AndFilter implements FilterInterface
{
    /**
     * @param list<FilterInterface> $filters
     */
    public function __construct(
        private readonly array $filters,
    ) {
        if ([] === $filters) {
            throw new InvalidArgumentException('The filter list must not be empty.');
        }

        foreach ($filters as $filter) {
            if (!$filter instanceof FilterInterface) {
                throw new InvalidArgumentException(\sprintf('All filters must implement "%s", "%s" given.', FilterInterface::class, get_debug_type($filter)));
            }
        }
    }

    /**
     * @return list<FilterInterface>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }
}
