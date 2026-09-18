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
final class ScoreAnswer implements AnswerInterface
{
    /**
     * @param float              $score         probability-weighted level index, e.g. 1.24 on a 0-2 scale
     * @param array<int, float>  $probabilities level index => probability
     * @param array<int, string> $legend        level index => level label
     */
    public function __construct(
        private readonly float $score,
        private readonly array $probabilities = [],
        private readonly array $legend = [],
        private readonly ?float $confidence = null,
    ) {
    }

    public function getScore(): float
    {
        return $this->score;
    }

    /**
     * @return array<int, float>
     */
    public function getProbabilities(): array
    {
        return $this->probabilities;
    }

    /**
     * @return array<int, string>
     */
    public function getLegend(): array
    {
        return $this->legend;
    }

    /**
     * Label of the level closest to the weighted score, or null if the legend does not name it.
     */
    public function getLevel(): ?string
    {
        return $this->legend[(int) round($this->score)] ?? null;
    }

    public function getConfidence(): ?float
    {
        return $this->confidence;
    }
}
