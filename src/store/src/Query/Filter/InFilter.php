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
 * Matches documents whose metadata field equals one of the given values.
 *
 * The values must be a non-empty list of the same scalar kind: all strings,
 * all numbers (int or float) or all booleans.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class InFilter implements FilterInterface
{
    /**
     * @param list<string>|list<int|float>|list<bool> $values
     */
    public function __construct(
        private readonly string $field,
        private readonly array $values,
    ) {
        if ('' === $field) {
            throw new InvalidArgumentException('The filter field must not be empty.');
        }

        if ([] === $values) {
            throw new InvalidArgumentException('The filter values must not be empty.');
        }

        $kind = null;
        foreach ($values as $value) {
            $valueKind = match (true) {
                \is_string($value) => 'string',
                \is_int($value), \is_float($value) => 'number',
                \is_bool($value) => 'boolean',
                default => throw new InvalidArgumentException(\sprintf('The filter values must be of type "string", "int", "float" or "bool", "%s" given.', get_debug_type($value))),
            };

            if (null === $kind) {
                $kind = $valueKind;

                continue;
            }

            if ($kind !== $valueKind) {
                throw new InvalidArgumentException(\sprintf('The filter values must all be of the same kind, got "%s" and "%s".', $kind, $valueKind));
            }
        }
    }

    public function getField(): string
    {
        return $this->field;
    }

    /**
     * @return list<string>|list<int|float>|list<bool>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
