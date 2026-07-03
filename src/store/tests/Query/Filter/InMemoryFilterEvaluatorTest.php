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
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Exception\UnsupportedFeatureException;
use Symfony\AI\Store\Query\Filter\AndFilter;
use Symfony\AI\Store\Query\Filter\EqualFilter;
use Symfony\AI\Store\Query\Filter\FilterInterface;
use Symfony\AI\Store\Query\Filter\GreaterThanFilter;
use Symfony\AI\Store\Query\Filter\GreaterThanOrEqualFilter;
use Symfony\AI\Store\Query\Filter\InFilter;
use Symfony\AI\Store\Query\Filter\InMemoryFilterEvaluator;
use Symfony\AI\Store\Query\Filter\LessThanFilter;
use Symfony\AI\Store\Query\Filter\LessThanOrEqualFilter;
use Symfony\AI\Store\Query\Filter\NotEqualFilter;
use Symfony\AI\Store\Query\Filter\OrFilter;

final class InMemoryFilterEvaluatorTest extends TestCase
{
    private InMemoryFilterEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new InMemoryFilterEvaluator();
    }

    public function testEqualFilterMatches()
    {
        $metadata = new Metadata(['locale' => 'en']);

        $this->assertTrue($this->evaluator->matches(new EqualFilter('locale', 'en'), $metadata));
        $this->assertFalse($this->evaluator->matches(new EqualFilter('locale', 'de'), $metadata));
    }

    public function testEqualFilterDoesNotMatchMissingField()
    {
        $this->assertFalse($this->evaluator->matches(new EqualFilter('locale', 'en'), new Metadata()));
    }

    public function testEqualFilterComparesNumbersAcrossIntAndFloat()
    {
        $metadata = new Metadata(['year' => 2024]);

        $this->assertTrue($this->evaluator->matches(new EqualFilter('year', 2024.0), $metadata));
        $this->assertFalse($this->evaluator->matches(new EqualFilter('year', 2023), $metadata));
    }

    public function testEqualFilterUsesStrictComparisonAcrossTypes()
    {
        $metadata = new Metadata(['year' => '2024']);

        $this->assertFalse($this->evaluator->matches(new EqualFilter('year', 2024), $metadata));
    }

    public function testNotEqualFilterMatches()
    {
        $metadata = new Metadata(['locale' => 'en']);

        $this->assertTrue($this->evaluator->matches(new NotEqualFilter('locale', 'de'), $metadata));
        $this->assertFalse($this->evaluator->matches(new NotEqualFilter('locale', 'en'), $metadata));
    }

    public function testNotEqualFilterMatchesMissingField()
    {
        $this->assertTrue($this->evaluator->matches(new NotEqualFilter('locale', 'en'), new Metadata()));
    }

    public function testInFilterMatches()
    {
        $metadata = new Metadata(['category' => 'scifi']);

        $this->assertTrue($this->evaluator->matches(new InFilter('category', ['scifi', 'fantasy']), $metadata));
        $this->assertFalse($this->evaluator->matches(new InFilter('category', ['crime', 'fantasy']), $metadata));
        $this->assertFalse($this->evaluator->matches(new InFilter('other', ['scifi']), $metadata));
    }

    public function testGreaterThanFilterMatches()
    {
        $metadata = new Metadata(['year' => 2024]);

        $this->assertTrue($this->evaluator->matches(new GreaterThanFilter('year', 2023), $metadata));
        $this->assertFalse($this->evaluator->matches(new GreaterThanFilter('year', 2024), $metadata));
        $this->assertFalse($this->evaluator->matches(new GreaterThanFilter('year', 2025), $metadata));
    }

    public function testGreaterThanOrEqualFilterMatches()
    {
        $metadata = new Metadata(['year' => 2024]);

        $this->assertTrue($this->evaluator->matches(new GreaterThanOrEqualFilter('year', 2024), $metadata));
        $this->assertFalse($this->evaluator->matches(new GreaterThanOrEqualFilter('year', 2025), $metadata));
    }

    public function testLessThanFilterMatches()
    {
        $metadata = new Metadata(['year' => 2024]);

        $this->assertTrue($this->evaluator->matches(new LessThanFilter('year', 2025), $metadata));
        $this->assertFalse($this->evaluator->matches(new LessThanFilter('year', 2024), $metadata));
    }

    public function testLessThanOrEqualFilterMatches()
    {
        $metadata = new Metadata(['year' => 2024]);

        $this->assertTrue($this->evaluator->matches(new LessThanOrEqualFilter('year', 2024), $metadata));
        $this->assertFalse($this->evaluator->matches(new LessThanOrEqualFilter('year', 2023), $metadata));
    }

    public function testComparisonFilterSupportsStrings()
    {
        $metadata = new Metadata(['title' => 'beta']);

        $this->assertTrue($this->evaluator->matches(new GreaterThanFilter('title', 'alpha'), $metadata));
        $this->assertFalse($this->evaluator->matches(new GreaterThanFilter('title', 'gamma'), $metadata));
    }

    public function testComparisonFilterDoesNotMatchOnTypeMismatch()
    {
        $metadata = new Metadata(['year' => '2024']);

        $this->assertFalse($this->evaluator->matches(new GreaterThanFilter('year', 2023), $metadata));
    }

    public function testComparisonFilterDoesNotMatchMissingField()
    {
        $this->assertFalse($this->evaluator->matches(new GreaterThanFilter('year', 2023), new Metadata()));
    }

    public function testAndFilterMatchesWhenAllMatch()
    {
        $metadata = new Metadata(['locale' => 'en', 'year' => 2024]);

        $this->assertTrue($this->evaluator->matches(new AndFilter([
            new EqualFilter('locale', 'en'),
            new GreaterThanFilter('year', 2023),
        ]), $metadata));

        $this->assertFalse($this->evaluator->matches(new AndFilter([
            new EqualFilter('locale', 'en'),
            new GreaterThanFilter('year', 2024),
        ]), $metadata));
    }

    public function testOrFilterMatchesWhenAnyMatches()
    {
        $metadata = new Metadata(['locale' => 'en']);

        $this->assertTrue($this->evaluator->matches(new OrFilter([
            new EqualFilter('locale', 'de'),
            new EqualFilter('locale', 'en'),
        ]), $metadata));

        $this->assertFalse($this->evaluator->matches(new OrFilter([
            new EqualFilter('locale', 'de'),
            new EqualFilter('locale', 'fr'),
        ]), $metadata));
    }

    public function testNestedCompositeFilters()
    {
        $metadata = new Metadata(['locale' => 'en', 'category' => 'scifi', 'year' => 2024]);

        $filter = new AndFilter([
            new EqualFilter('locale', 'en'),
            new OrFilter([
                new EqualFilter('category', 'fantasy'),
                new AndFilter([
                    new EqualFilter('category', 'scifi'),
                    new LessThanOrEqualFilter('year', 2024),
                ]),
            ]),
        ]);

        $this->assertTrue($this->evaluator->matches($filter, $metadata));
    }

    public function testThrowsOnUnsupportedFilterType()
    {
        $filter = new class implements FilterInterface {};

        $this->expectException(UnsupportedFeatureException::class);
        $this->expectExceptionMessage('is not supported by "Symfony\AI\Store\Query\Filter\InMemoryFilterEvaluator"');

        $this->evaluator->matches($filter, new Metadata());
    }
}
