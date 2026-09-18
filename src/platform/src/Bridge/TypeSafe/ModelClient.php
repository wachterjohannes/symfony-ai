<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\TypeSafe;

use Symfony\AI\Platform\Classification\BooleanQuestion;
use Symfony\AI\Platform\Classification\ChoiceQuestion;
use Symfony\AI\Platform\Classification\QuestionInterface;
use Symfony\AI\Platform\Classification\ScoreQuestion;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sends a state and a map of typed questions to TypeSafe's classification endpoint.
 *
 * The state is the invocation input, the questions are passed as the "questions" option.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ModelClient implements ModelClientInterface
{
    private readonly string $baseUrl;

    /**
     * @param string $baseUrl Base URL of a TypeSafe-compatible endpoint, with or without a trailing slash
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[\SensitiveParameter] private readonly string $apiKey,
        string $baseUrl = 'https://api.typesafe.ai',
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function supports(Model $model): bool
    {
        return $model instanceof TypeSafe;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if ('' === $payload || [] === $payload) {
            throw new InvalidArgumentException('The state to classify must not be empty.');
        }

        $questions = $options['questions'] ?? [];
        if (!\is_array($questions) || [] === $questions) {
            throw new InvalidArgumentException('The "questions" option must be a non-empty map of question names to questions.');
        }

        $wireQuestions = [];
        foreach ($questions as $name => $question) {
            if (!$question instanceof QuestionInterface) {
                throw new InvalidArgumentException(\sprintf('The question "%s" must be an instance of "%s", "%s" given.', $name, QuestionInterface::class, get_debug_type($question)));
            }

            $wireQuestions[$name] = $this->convertQuestion($question);
        }

        return new RawHttpResult($this->httpClient->request('POST', $this->baseUrl.'/v1/systemone', [
            'auth_bearer' => $this->apiKey,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $model->getName(),
                'state' => $payload,
                // objects keep numeric-looking question names and option labels from being encoded as JSON lists
                'questions' => (object) $wireQuestions,
            ],
        ]));
    }

    /**
     * @return array{type: string, instructions: string, criteria?: object|list<string>}
     */
    private function convertQuestion(QuestionInterface $question): array
    {
        if ($question instanceof BooleanQuestion) {
            $wireQuestion = ['type' => 'noul', 'instructions' => $question->getInstructions()];

            $criteria = [];
            if (null !== $question->getTrueCriterion()) {
                $criteria['true'] = $question->getTrueCriterion();
            }
            if (null !== $question->getFalseCriterion()) {
                $criteria['false'] = $question->getFalseCriterion();
            }
            if ([] !== $criteria) {
                $wireQuestion['criteria'] = (object) $criteria;
            }

            return $wireQuestion;
        }

        if ($question instanceof ChoiceQuestion) {
            return [
                'type' => 'choice',
                'instructions' => $question->getInstructions(),
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
