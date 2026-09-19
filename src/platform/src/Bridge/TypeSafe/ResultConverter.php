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

use Symfony\AI\Platform\Classification\AnswerInterface;
use Symfony\AI\Platform\Classification\BooleanAnswer;
use Symfony\AI\Platform\Classification\ChoiceAnswer;
use Symfony\AI\Platform\Classification\ScoreAnswer;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ClassificationResult;
use Symfony\AI\Platform\Result\HttpStatusErrorHandlingTrait;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ResultConverter implements ResultConverterInterface
{
    use HttpStatusErrorHandlingTrait;

    public function supports(Model $model): bool
    {
        return $model instanceof TypeSafe;
    }

    public function convert(RawResultInterface|RawHttpResult $result, array $options = []): ClassificationResult
    {
        $httpResponse = $result->getObject();

        $this->throwOnHttpError($httpResponse);

        if (200 !== $httpResponse->getStatusCode()) {
            throw new RuntimeException(\sprintf('Unexpected response code %d: "%s"', $httpResponse->getStatusCode(), $httpResponse->getContent(false)));
        }

        $data = $result->getData();

        if (!isset($data['answers']) || !\is_array($data['answers'])) {
            throw new RuntimeException('Response does not contain classification answers.');
        }

        $answers = [];
        foreach ($data['answers'] as $name => $answer) {
            if (!\is_array($answer)) {
                throw new RuntimeException(\sprintf('Malformed answer for question "%s".', $name));
            }

            $answers[(string) $name] = $this->convertAnswer((string) $name, $answer);
        }

        return new ClassificationResult($answers);
    }

    public function getTokenUsageExtractor(): TokenUsageExtractor
    {
        return new TokenUsageExtractor();
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function convertAnswer(string $name, array $answer): AnswerInterface
    {
        $type = $answer['type'] ?? null;

        // the answer value is keyed by its wire type, e.g. {"type": "noul", "noul": 0.98}
        if (!\is_string($type) || !\array_key_exists($type, $answer)) {
            throw new RuntimeException(\sprintf('Malformed answer for question "%s".', $name));
        }

        $confidence = isset($answer['confidence']) ? (float) $answer['confidence'] : null;

        return match ($type) {
            // a noul answer is the calibrated probability that the question is true, not a boolean
            'noul' => new BooleanAnswer((float) $answer['noul'], $confidence),
            'choice' => new ChoiceAnswer(
                (string) $answer['choice'],
                array_map(floatval(...), $answer['probabilities'] ?? []),
                $confidence,
            ),
            'score' => new ScoreAnswer(
                (float) $answer['score'],
                array_map(floatval(...), $this->withIntegerKeys($answer['probabilities'] ?? [])),
                array_map(strval(...), $this->withIntegerKeys($answer['legend'] ?? [])),
                $confidence,
            ),
            default => throw new RuntimeException(\sprintf('Unsupported answer type "%s" for question "%s".', $type, $name)),
        };
    }

    /**
     * Score probabilities and legends arrive as JSON lists, so normalize them to integer level indexes.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<int, mixed>
     */
    private function withIntegerKeys(array $values): array
    {
        $converted = [];
        foreach ($values as $key => $value) {
            $converted[(int) $key] = $value;
        }

        return $converted;
    }
}
