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

use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ProfilerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;

/**
 * Builds a per-token request graph by replaying the static slice and overlaying the request-
 * scoped nodes contributed by {@see ProfilerGraphProvider}.
 *
 * Cached per token via {@see RequestGraphCache} — a profiler token is immutable, so the first
 * successful build is reusable until the cache file is removed (e.g. by `cache:clear`).
 *
 * Returns `null` when the requested token doesn't resolve to a profile; callers (notably
 * {@see \Symfony\AI\Mate\Bridge\Symfony\Capability\ContextTool}) translate that into a
 * user-facing warning envelope.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class RequestGraphFactory
{
    public function __construct(
        private readonly StaticGraphFactory $staticFactory,
        private readonly ProfilerGraphProvider $profilerProvider,
        private readonly RequestGraphCache $cache,
        private readonly ProfilerDataProvider $profiler,
    ) {
    }

    public function build(string $token): ?RuntimeGraph
    {
        if (null === $this->profiler->findProfile($token)) {
            return null;
        }

        $cached = $this->cache->load($token);
        if (null !== $cached) {
            return $cached;
        }

        // Replay the static slice into a fresh RuntimeGraph so the cached static graph stays
        // pristine. RuntimeGraph::addNode/addEdge dedup, so replays are idempotent.
        $base = new RuntimeGraph();
        $staticGraph = $this->staticFactory->build();
        foreach ($staticGraph->nodes() as $node) {
            $base->addNode($node);
        }
        foreach ($staticGraph->edges() as $edge) {
            $base->addEdge($edge);
        }

        $builder = new RuntimeGraphBuilder($base);
        $this->profilerProvider->populate($builder, new GraphContext(profilerToken: $token));

        $this->cache->save($token, $base);

        return $base;
    }
}
