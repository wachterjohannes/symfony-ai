<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Query;

use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Query\Filter\FilterInterface;

/**
 * Combined vector + text search query for hybrid retrieval.
 *
 * Hybrid queries combine semantic similarity (vector search) with keyword
 * matching (full-text search) to provide more accurate and relevant results.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class HybridQuery implements QueryInterface
{
    public function __construct(
        private readonly Vector $vector,
        private readonly string $text,
        private readonly float $semanticRatio = 0.5,
        private readonly ?FilterInterface $filter = null,
    ) {
        if ($semanticRatio < 0.0 || $semanticRatio > 1.0) {
            throw new \InvalidArgumentException(\sprintf('Semantic ratio must be between 0.0 and 1.0, got %.2f', $semanticRatio));
        }
    }

    public function getVector(): Vector
    {
        return $this->vector;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getSemanticRatio(): float
    {
        return $this->semanticRatio;
    }

    public function getKeywordRatio(): float
    {
        return 1.0 - $this->semanticRatio;
    }

    public function getFilter(): ?FilterInterface
    {
        return $this->filter;
    }

    public function getType(): QueryType
    {
        return QueryType::Hybrid;
    }
}
