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
 * Marker interface for query-level metadata filters.
 *
 * Filters express constraints on document metadata and are attached to query
 * objects. Stores translate them into their native filtering mechanism.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface FilterInterface
{
}
