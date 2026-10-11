<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Reranker\TypeSafe;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\TypeSafe\Answer\Answers;
use Symfony\AI\Platform\Bridge\TypeSafe\Evaluation;
use Symfony\AI\Platform\Bridge\TypeSafe\Question\NoulQuestion;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Store\Document\VectorDocumentInterface;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Reranker\RerankerInterface;

/**
 * Reranks documents by asking TypeSafe's Jev whether each of them helps answering the query.
 *
 * Ranking and answerability are different questions: next to the relevance of every candidate, one extra
 * "gate" question asks whether the candidates suffice to answer the query at all. {@see rerank()} only
 * returns the relevant candidates, {@see evaluate()} gives access to the gate as well.
 *
 * A query is one evaluation: the query is the shared state and every candidate is a yes/no question.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class TypeSafeReranker implements RerankerInterface
{
    private const GATE = 'gate';

    /**
     * @param float $threshold              The probability from which a candidate counts as relevant
     * @param float $gateThreshold          The probability from which the candidates count as sufficient to answer the query
     * @param int   $maxQuestionsPerRequest The maximum number of candidates evaluated in one request, more candidates are split
     *                                      over several requests. The limit of the API is unverified, so the default is conservative.
     */
    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly string $model = 'jev-latest',
        private readonly float $threshold = 0.5,
        private readonly float $gateThreshold = 0.5,
        private readonly int $maxQuestionsPerRequest = 10,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        if ($threshold < 0.0 || $threshold > 1.0 || $gateThreshold < 0.0 || $gateThreshold > 1.0) {
            throw new InvalidArgumentException('The thresholds must be probabilities between 0 and 1.');
        }

        if ($maxQuestionsPerRequest < 1) {
            throw new InvalidArgumentException('The maximum number of questions per request must be at least 1.');
        }
    }

    public function rerank(string $query, array $documents, int $topK = 5): array
    {
        return $this->evaluate($query, $documents, $topK)->getDocuments();
    }

    /**
     * @param list<VectorDocumentInterface> $documents
     */
    public function evaluate(string $query, array $documents, int $topK = 5): RerankingEvaluation
    {
        if ([] === $documents) {
            return new RerankingEvaluation([], 0.0, $this->gateThreshold);
        }

        $this->logger->debug('Evaluating {count} documents with TypeSafe', ['count' => \count($documents)]);

        $scored = [];
        $answerability = 0.0;

        foreach (array_chunk($documents, $this->maxQuestionsPerRequest, true) as $chunk) {
            $answers = $this->platform
                ->invoke($this->model, $this->createEvaluation($query, $chunk))
                ->asObject();

            if (!$answers instanceof Answers) {
                throw new InvalidArgumentException(\sprintf('Expected the "%s" model to answer with "%s", "%s" given.', $this->model, Answers::class, get_debug_type($answers)));
            }

            foreach ($chunk as $index => $document) {
                $probability = $answers->getNoul(self::candidateKey($index))->getProbability();

                if ($probability >= $this->threshold) {
                    $scored[] = [$probability, $index, $document->withScore($probability)];
                }
            }

            // The candidates of other requests are not part of the gate, so the best one stands for the whole set
            $answerability = max($answerability, $answers->getNoul(self::GATE)->getProbability());
        }

        // The index keeps equal probabilities in the order of retrieval
        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        $reranked = array_map(static fn (array $entry): VectorDocumentInterface => $entry[2], \array_slice($scored, 0, $topK));

        $this->logger->debug('Evaluation completed, returning {topK} documents, answerability {answerability}', [
            'topK' => \count($reranked),
            'answerability' => $answerability,
        ]);

        return new RerankingEvaluation($reranked, $answerability, $this->gateThreshold);
    }

    /**
     * @param array<int, VectorDocumentInterface> $candidates The candidates keyed by their position in the list to rerank
     */
    private function createEvaluation(string $query, array $candidates): Evaluation
    {
        $questions = [];
        $passages = [];

        foreach ($candidates as $index => $candidate) {
            $passage = $candidate->getMetadata()->getText() ?? $candidate->getMetadata()->getSource() ?? '';
            $passages[] = $passage;

            $questions[self::candidateKey($index)] = new NoulQuestion(
                \sprintf("Does the following passage contain information that helps answering the query?\n\nPassage:\n%s", $passage),
                'The passage helps answering the query',
                'The passage is unrelated to the query or does not help answering it',
            );
        }

        $questions[self::GATE] = new NoulQuestion(
            \sprintf("Do the following passages together contain enough information to answer the query completely?\n\n%s", implode("\n\n", array_map(
                static fn (int $position, string $passage): string => \sprintf("Passage %d:\n%s", $position + 1, $passage),
                array_keys($passages),
                $passages,
            ))),
            'The passages suffice to answer the query',
            'The query cannot be answered from the passages',
        );

        return new Evaluation($query, $questions);
    }

    private static function candidateKey(int $index): string
    {
        return 'candidate_'.$index;
    }
}
