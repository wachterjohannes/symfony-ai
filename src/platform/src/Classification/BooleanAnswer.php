<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Classification;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class BooleanAnswer implements AnswerInterface
{
    public function __construct(
        private readonly bool $value,
        private readonly ?float $confidence = null,
    ) {
    }

    public function getValue(): bool
    {
        return $this->value;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }
}
