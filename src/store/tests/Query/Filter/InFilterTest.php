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
use Symfony\AI\Store\Query\Filter\InFilter;

final class InFilterTest extends TestCase
{
    public function testExposesFieldAndValues()
    {
        $filter = new InFilter('category', ['scifi', 'fantasy']);

        $this->assertSame('category', $filter->getField());
        $this->assertSame(['scifi', 'fantasy'], $filter->getValues());
    }

    public function testAcceptsMixedNumericValues()
    {
        $filter = new InFilter('year', [2023, 2024.5]);

        $this->assertSame([2023, 2024.5], $filter->getValues());
    }

    public function testThrowsOnEmptyField()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The filter field must not be empty.');

        new InFilter('', ['scifi']);
    }

    public function testThrowsOnEmptyValues()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The filter values must not be empty.');

        new InFilter('category', []);
    }

    public function testThrowsOnUnsupportedValueType()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The filter values must be of type "string", "int", "float" or "bool", "null" given.');

        new InFilter('category', ['scifi', null]); /* @phpstan-ignore argument.type */
    }

    public function testThrowsOnMixedValueKinds()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The filter values must all be of the same kind, got "string" and "number".');

        new InFilter('category', ['scifi', 42]);
    }
}
