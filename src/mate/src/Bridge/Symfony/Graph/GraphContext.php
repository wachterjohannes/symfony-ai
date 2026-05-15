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
 * Passes scope hints to {@see \Symfony\AI\Mate\Bridge\Symfony\GraphProvider\GraphProviderInterface}
 * implementations so they can populate only the slice that matters for the current request.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final readonly class GraphContext
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public ?string $profilerToken = null,
        public ?string $route = null,
        public ?string $service = null,
        public array $scopes = [],
        public int $depth = 2,
    ) {
    }
}
