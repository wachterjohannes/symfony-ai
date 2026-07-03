<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Tests\Query;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Query\Filter\EqualFilter;
use Symfony\AI\Store\Query\HybridQuery;

final class HybridQueryTest extends TestCase
{
    public function testFilterDefaultsToNull()
    {
        $query = new HybridQuery(new Vector([0.1, 0.2, 0.3]), 'space adventure');

        $this->assertNull($query->getFilter());
    }

    public function testFilterIsExposed()
    {
        $filter = new EqualFilter('category', 'scifi');
        $query = new HybridQuery(new Vector([0.1, 0.2, 0.3]), 'space adventure', 0.5, $filter);

        $this->assertSame($filter, $query->getFilter());
    }
}
