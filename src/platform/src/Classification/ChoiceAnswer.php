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
final class ChoiceAnswer implements AnswerInterface
{
    /**
     * @param array<string, float> $probabilities option label => probability
     */
    public function __construct(
        private readonly string $choice,
        private readonly array $probabilities = [],
        private readonly ?float $confidence = null,
    ) {
    }

    public function getChoice(): string
    {
        return $this->choice;
    }

    /**
     * @return array<string, float>
     */
    public function getProbabilities(): array
    {
        return $this->probabilities;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }
}
