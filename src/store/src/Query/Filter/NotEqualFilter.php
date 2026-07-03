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

use Symfony\AI\Store\Exception\InvalidArgumentException;

/**
 * Matches documents whose metadata field does not equal the given value.
 *
 * Documents that do not define the field at all are considered matching,
 * consistent with the native behavior of the supported store backends.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class NotEqualFilter implements FilterInterface
{
    public function __construct(
        private readonly string $field,
        private readonly string|int|float|bool $value,
    ) {
        if ('' === $field) {
            throw new InvalidArgumentException('The filter field must not be empty.');
        }
    }

    public function getField(): string
    {
        return $this->field;
    }

    public function getValue(): string|int|float|bool
    {
        return $this->value;
    }
}
