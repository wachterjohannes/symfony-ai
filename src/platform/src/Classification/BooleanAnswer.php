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

use Symfony\AI\Platform\Exception\InvalidArgumentException;

/**
 * Answer to a {@see BooleanQuestion}, carrying the calibrated probability that it is true.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class BooleanAnswer implements AnswerInterface
{
    /**
     * @param float $probability calibrated probability that the answer is true, between 0 and 1
     */
    public function __construct(
        private readonly float $probability,
        private readonly ?float $confidence = null,
    ) {
        if ($probability < 0.0 || $probability > 1.0) {
            throw new InvalidArgumentException(\sprintf('The probability must be between 0 and 1, "%s" given.', $probability));
        }
    }

    public function getProbability(): float
    {
        return $this->probability;
    }

    /**
     * Whether the probability makes the answer more likely true than false.
     *
     * Decisions that should only be taken on a clear signal read the probability instead.
     */
    public function getValue(): bool
    {
        return $this->probability >= 0.5;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }
}
