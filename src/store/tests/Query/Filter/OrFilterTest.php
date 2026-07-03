<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Tests\Query\Filter;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Query\Filter\EqualFilter;
use Symfony\AI\Store\Query\Filter\OrFilter;

final class OrFilterTest extends TestCase
{
    public function testExposesFilters()
    {
        $first = new EqualFilter('locale', 'en');
        $second = new EqualFilter('category', 'scifi');

        $filter = new OrFilter([$first, $second]);

        $this->assertSame([$first, $second], $filter->getFilters());
    }

    public function testThrowsOnEmptyFilters()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The filter list must not be empty.');

        new OrFilter([]);
    }

    public function testThrowsOnInvalidFilterType()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('All filters must implement "Symfony\AI\Store\Query\Filter\FilterInterface", "string" given.');

        new OrFilter([new EqualFilter('locale', 'en'), 'not-a-filter']); /* @phpstan-ignore argument.type */
    }
}
