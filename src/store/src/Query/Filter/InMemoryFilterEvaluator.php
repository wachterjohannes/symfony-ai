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

use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Exception\UnsupportedFeatureException;

/**
 * Evaluates query-level filters against document metadata in PHP.
 *
 * Used by the in-memory store and reusable by any store that filters
 * array-based documents on the PHP side instead of translating filters
 * into a native query language.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class InMemoryFilterEvaluator
{
    public function matches(FilterInterface $filter, Metadata $metadata): bool
    {
        return match (true) {
            $filter instanceof AndFilter => $this->matchesAll($filter->getFilters(), $metadata),
            $filter instanceof OrFilter => $this->matchesAny($filter->getFilters(), $metadata),
            $filter instanceof EqualFilter => $metadata->offsetExists($filter->getField()) && $this->equals($metadata->offsetGet($filter->getField()), $filter->getValue()),
            $filter instanceof NotEqualFilter => !$metadata->offsetExists($filter->getField()) || !$this->equals($metadata->offsetGet($filter->getField()), $filter->getValue()),
            $filter instanceof InFilter => $this->matchesIn($filter, $metadata),
            $filter instanceof GreaterThanFilter,
            $filter instanceof GreaterThanOrEqualFilter,
            $filter instanceof LessThanFilter,
            $filter instanceof LessThanOrEqualFilter => $this->matchesComparison($filter, $metadata),
            default => throw new UnsupportedFeatureException(\sprintf('Filter type "%s" is not supported by "%s".', $filter::class, self::class)),
        };
    }

    /**
     * @param list<FilterInterface> $filters
     */
    private function matchesAll(array $filters, Metadata $metadata): bool
    {
        foreach ($filters as $filter) {
            if (!$this->matches($filter, $metadata)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<FilterInterface> $filters
     */
    private function matchesAny(array $filters, Metadata $metadata): bool
    {
        foreach ($filters as $filter) {
            if ($this->matches($filter, $metadata)) {
                return true;
            }
        }

        return false;
    }

    private function matchesIn(InFilter $filter, Metadata $metadata): bool
    {
        if (!$metadata->offsetExists($filter->getField())) {
            return false;
        }

        $actual = $metadata->offsetGet($filter->getField());

        foreach ($filter->getValues() as $value) {
            if ($this->equals($actual, $value)) {
                return true;
            }
        }

        return false;
    }

    private function matchesComparison(GreaterThanFilter|GreaterThanOrEqualFilter|LessThanFilter|LessThanOrEqualFilter $filter, Metadata $metadata): bool
    {
        $comparison = $this->compare($filter->getField(), $filter->getValue(), $metadata);

        if (null === $comparison) {
            return false;
        }

        return match (true) {
            $filter instanceof GreaterThanFilter => 0 < $comparison,
            $filter instanceof GreaterThanOrEqualFilter => 0 <= $comparison,
            $filter instanceof LessThanFilter => 0 > $comparison,
            $filter instanceof LessThanOrEqualFilter => 0 >= $comparison,
        };
    }

    private function equals(mixed $actual, string|int|float|bool $expected): bool
    {
        if ((\is_int($actual) || \is_float($actual)) && (\is_int($expected) || \is_float($expected))) {
            return (float) $actual === (float) $expected;
        }

        return $actual === $expected;
    }

    /**
     * Compares the metadata value of the given field with the expected value.
     *
     * Returns the spaceship result (metadata value <=> expected value) or
     * null if the values are not comparable, because the field is missing or
     * the types do not match.
     */
    private function compare(string $field, string|int|float $expected, Metadata $metadata): ?int
    {
        if (!$metadata->offsetExists($field)) {
            return null;
        }

        $actual = $metadata->offsetGet($field);

        if (\is_string($actual) && \is_string($expected)) {
            return $actual <=> $expected;
        }

        if ((\is_int($actual) || \is_float($actual)) && !\is_string($expected)) {
            return $actual <=> $expected;
        }

        return null;
    }
}
