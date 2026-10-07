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

use Symfony\AI\Store\Document\VectorDocumentInterface;

/**
 * The outcome of {@see TypeSafeReranker::evaluate()}: the documents that help answering the query, and
 * how well they answer it together.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RerankingEvaluation
{
    /**
     * @param list<VectorDocumentInterface> $documents     The relevant documents, best first, scored by their probability
     * @param float                         $answerability The probability that the candidates suffice to answer the query
     * @param float                         $gateThreshold The probability from which the candidates count as sufficient
     */
    public function __construct(
        private readonly array $documents,
        private readonly float $answerability,
        private readonly float $gateThreshold,
    ) {
    }

    /**
     * @return list<VectorDocumentInterface>
     */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    public function getAnswerability(): float
    {
        return $this->answerability;
    }

    /**
     * Whether the candidates suffice to answer the query. When they do not, the caller can skip the LLM
     * and answer that the information is not part of the documents.
     */
    public function isAnswerable(): bool
    {
        return $this->answerability >= $this->gateThreshold;
    }
}
