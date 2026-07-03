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
use Symfony\AI\Store\Query\Filter\EqualFilter;
use Symfony\AI\Store\Query\TextQuery;

final class TextQueryTest extends TestCase
{
    public function testFilterDefaultsToNull()
    {
        $query = new TextQuery('space adventure');

        $this->assertNull($query->getFilter());
    }

    public function testFilterIsExposed()
    {
        $filter = new EqualFilter('category', 'scifi');
        $query = new TextQuery('space adventure', $filter);

        $this->assertSame($filter, $query->getFilter());
    }
}
