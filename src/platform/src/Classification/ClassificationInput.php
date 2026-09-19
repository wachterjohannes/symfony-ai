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
 * The input of a classification: a state, and the typed questions to answer about it.
 *
 * A classification model ingests the state once and answers every question against it,
 * so batching questions into a single input is cheaper than asking them one by one.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ClassificationInput
{
    /**
     * @param string|array<string, mixed>      $state     the content to classify, as plain text or structured data
     * @param array<string, QuestionInterface> $questions the questions, keyed by the name their answer is returned under
     */
    public function __construct(
        private readonly string|array $state,
        private readonly array $questions,
    ) {
        if ('' === $state || [] === $state) {
            throw new InvalidArgumentException('The state to classify must not be empty.');
        }

        if ([] === $questions) {
            throw new InvalidArgumentException('A classification requires at least one question.');
        }

        foreach ($questions as $name => $question) {
            if (!$question instanceof QuestionInterface) {
                throw new InvalidArgumentException(\sprintf('The question "%s" must be an instance of "%s", "%s" given.', $name, QuestionInterface::class, get_debug_type($question)));
            }
        }
    }

    /**
     * @return string|array<string, mixed>
     */
    public function getState(): string|array
    {
        return $this->state;
    }

    /**
     * @return array<string, QuestionInterface>
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }
}
