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
use Symfony\AI\Store\Query\VectorQuery;

final class VectorQueryTest extends TestCase
{
    public function testFilterDefaultsToNull()
    {
        $query = new VectorQuery(new Vector([0.1, 0.2, 0.3]));

        $this->assertNull($query->getFilter());
    }

    public function testFilterIsExposed()
    {
        $filter = new EqualFilter('locale', 'en');
        $query = new VectorQuery(new Vector([0.1, 0.2, 0.3]), $filter);

        $this->assertSame($filter, $query->getFilter());
    }
}
