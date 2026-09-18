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
 * Places the state on an ordered scale of levels, from lowest to highest.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ScoreQuestion implements QuestionInterface
{
    /**
     * @param list<string> $levels ordered level labels, lowest first
     */
    public function __construct(
        private readonly string $instructions,
        private readonly array $levels,
    ) {
        if (\count($levels) < 2) {
            throw new InvalidArgumentException('A score question requires at least two levels.');
        }
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    /**
     * @return list<string>
     */
    public function getLevels(): array
    {
        return $this->levels;
    }
}
