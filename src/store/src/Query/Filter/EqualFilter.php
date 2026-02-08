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
 * Filter that matches documents where a field equals a specific value.
 *
 * Common use cases:
 * - Filtering by locale: new EqualFilter('locale', 'en')
 * - Filtering by category: new EqualFilter('category', 'news')
 * - Filtering by status: new EqualFilter('status', 'published')
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class EqualFilter implements FilterInterface
{
    public function __construct(
        private readonly string $field,
        private readonly mixed $value,
    ) {
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }

    public function getType(): string
    {
        return 'equal';
    }

    public function toArray(): array
    {
        return [
            'type' => $this->getType(),
            'field' => $this->field,
            'value' => $this->value,
        ];
    }
}
