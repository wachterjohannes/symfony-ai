<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\TypeSafe\Contract;

use Symfony\AI\Platform\Bridge\TypeSafe\TypeSafe;
use Symfony\AI\Platform\Classification\BooleanQuestion;
use Symfony\AI\Platform\Classification\ChoiceQuestion;
use Symfony\AI\Platform\Classification\ClassificationInput;
use Symfony\AI\Platform\Classification\QuestionInterface;
use Symfony\AI\Platform\Classification\ScoreQuestion;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;

/**
 * Converts the provider-agnostic classification input into TypeSafe's wire format.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ClassificationInputNormalizer extends ModelContractNormalizer
{
    /**
     * @param ClassificationInput $data
     *
     * @return array{state: string|array<string, mixed>, questions: \stdClass}
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        $questions = [];
        foreach ($data->getQuestions() as $name => $question) {
            $questions[$name] = $this->normalizeQuestion($question);
        }

        return [
            'state' => $data->getState(),
            // an object keeps numeric-looking question names from being encoded as a JSON list
            'questions' => (object) $questions,
        ];
    }

    protected function supportedDataClass(): string
    {
        return ClassificationInput::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof TypeSafe;
    }

    /**
     * @return array{type: string, instructions: string, criteria?: \stdClass|list<string>}
     */
    private function normalizeQuestion(QuestionInterface $question): array
    {
        if ($question instanceof BooleanQuestion) {
            $normalized = ['type' => 'noul', 'instructions' => $question->getInstructions()];

            $criteria = [];
            if (null !== $question->getTrueCriterion()) {
                $criteria['true'] = $question->getTrueCriterion();
            }
            if (null !== $question->getFalseCriterion()) {
                $criteria['false'] = $question->getFalseCriterion();
            }
            if ([] !== $criteria) {
                $normalized['criteria'] = (object) $criteria;
            }

            return $normalized;
        }

        if ($question instanceof ChoiceQuestion) {
            return [
                'type' => 'choice',
                'instructions' => $question->getInstructions(),
                // an object keeps numeric-looking option labels from being encoded as a JSON list
                'criteria' => (object) $question->getOptions(),
            ];
        }

        if ($question instanceof ScoreQuestion) {
            return [
                'type' => 'score',
                'instructions' => $question->getInstructions(),
                'criteria' => $question->getLevels(),
            ];
        }

        throw new InvalidArgumentException(\sprintf('Question type "%s" is not supported by TypeSafe.', $question::class));
    }
}
