<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Result;

use Symfony\AI\Platform\Classification\AnswerInterface;
use Symfony\AI\Platform\Exception\InvalidArgumentException;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ClassificationResult extends BaseResult
{
    /**
     * @param array<string, AnswerInterface> $answers question name => answer
     */
    public function __construct(
        private readonly array $answers = [],
    ) {
    }

    /**
     * @return array<string, AnswerInterface>
     */
    public function getContent(): array
    {
        return $this->answers;
    }

    public function getAnswer(string $name): AnswerInterface
    {
        if (!isset($this->answers[$name])) {
            throw new InvalidArgumentException(\sprintf('The classification result does not contain an answer for "%s".', $name));
        }

        return $this->answers[$name];
    }
}
