<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Graph;

/**
 * A directed, typed relationship between two nodes in the runtime graph.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final readonly class GraphEdge
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $from,
        public string $relation,
        public string $to,
        public array $metadata = [],
    ) {
    }
}
