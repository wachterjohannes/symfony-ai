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
 * Picks exactly one of the given labeled options.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ChoiceQuestion implements QuestionInterface
{
    /**
     * @param array<string, string> $options option label => description of when it applies
     */
    public function __construct(
        private readonly string $instructions,
        private readonly array $options,
    ) {
        if (\count($options) < 2) {
            throw new InvalidArgumentException('A choice question requires at least two options.');
        }
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    /**
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
