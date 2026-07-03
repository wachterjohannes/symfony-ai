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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Query\Filter\EqualFilter;
use Symfony\AI\Store\Query\Filter\FilterInterface;
use Symfony\AI\Store\Query\Filter\GreaterThanFilter;
use Symfony\AI\Store\Query\Filter\GreaterThanOrEqualFilter;
use Symfony\AI\Store\Query\Filter\LessThanFilter;
use Symfony\AI\Store\Query\Filter\LessThanOrEqualFilter;
use Symfony\AI\Store\Query\Filter\NotEqualFilter;

final class ComparisonFilterTest extends TestCase
{
    /**
     * @param class-string<EqualFilter|NotEqualFilter|GreaterThanFilter|GreaterThanOrEqualFilter|LessThanFilter|LessThanOrEqualFilter> $filterClass
     */
    #[DataProvider('provideFilterClasses')]
    public function testExposesFieldAndValue(string $filterClass)
    {
        $filter = new $filterClass('locale', 'en');

        $this->assertInstanceOf(FilterInterface::class, $filter);
        $this->assertSame('locale', $filter->getField());
        $this->assertSame('en', $filter->getValue());
    }

    /**
     * @param class-string<EqualFilter|NotEqualFilter|GreaterThanFilter|GreaterThanOrEqualFilter|LessThanFilter|LessThanOrEqualFilter> $filterClass
     */
    #[DataProvider('provideFilterClasses')]
    public function testThrowsOnEmptyField(string $filterClass)
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The filter field must not be empty.');

        new $filterClass('', 'en');
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function provideFilterClasses(): iterable
    {
        yield 'equal' => [EqualFilter::class];
        yield 'not equal' => [NotEqualFilter::class];
        yield 'greater than' => [GreaterThanFilter::class];
        yield 'greater than or equal' => [GreaterThanOrEqualFilter::class];
        yield 'less than' => [LessThanFilter::class];
        yield 'less than or equal' => [LessThanOrEqualFilter::class];
    }
}
