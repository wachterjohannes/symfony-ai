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

use Symfony\AI\Store\Query\Filter\FilterInterface;

/**
 * Full-text search query using textual keywords.
 *
 * Used for:
 * - FTS-only backends that don't support vectors
 * - Internal vectorization (e.g., ChromaDB's queryTexts)
 * - Retriever pre-extensions that transform to HybridQuery
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class TextQuery implements QueryInterface
{
    public function __construct(
        private readonly string $text,
        private readonly ?FilterInterface $filter = null,
    ) {
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getFilter(): ?FilterInterface
    {
        return $this->filter;
    }

    public function getType(): QueryType
    {
        return QueryType::Text;
    }
}
