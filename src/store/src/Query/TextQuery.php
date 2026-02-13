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
    ) {
    }

    public function getText(): string
    {
        return $this->text;
    }
}
