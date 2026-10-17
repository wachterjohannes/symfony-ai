<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Bridge\Doctrine\Mirror;

use Symfony\AI\Store\RetrieverInterface;

/**
 * Answers a semantic query with managed Doctrine entities instead of documents.
 *
 * Composes the core {@see RetrieverInterface} (which handles query vectorization, hybrid/text fallbacks and
 * pre/post query events) with the {@see EntityHydrator}, completing the round trip started by the
 * {@see MirroredEntityLoader}: entities in, entities out.
 *
 * Mirrors the core Retriever API shape. The returned entities keep the ranking order of the underlying store
 * results. Stale results (entities deleted since indexing) are skipped, see EntityHydrator.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class MirroredEntityRetriever
{
    public function __construct(
        private readonly RetrieverInterface $retriever,
        private readonly EntityHydrator $hydrator,
    ) {
    }

    /**
     * Retrieve the entities most similar to the given query.
     *
     * @param string               $query   The search query to vectorize and use for similarity search
     * @param array<string, mixed> $options Options passed to the underlying retriever (e.g. limit, filters)
     *
     * @return list<object> managed entities in result ranking order
     */
    public function retrieve(string $query, array $options = []): array
    {
        return $this->hydrator->hydrate($this->retriever->retrieve($query, $options));
    }
}
