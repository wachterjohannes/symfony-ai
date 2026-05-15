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

use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\GraphProviderInterface;

/**
 * Orchestrates the tagged graph providers to assemble the static slice of the runtime graph.
 *
 * Caches the result via {@see StaticGraphCache}. The cache key is derived from the first
 * discovered container XML file's mtime + size — cheap to compute and stable enough for the
 * "did the container rebuild?" question we actually need to answer.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class StaticGraphFactory
{
    /**
     * @param iterable<GraphProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly StaticGraphCache $cache,
        private readonly string $cacheDir,
    ) {
    }

    public function build(): RuntimeGraph
    {
        $hash = $this->computeContainerHash();

        if (null !== $hash) {
            $cached = $this->cache->load($hash);
            if (null !== $cached) {
                return $cached;
            }
        }

        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);
        $context = new GraphContext();

        foreach ($this->providers as $provider) {
            $provider->populate($builder, $context);
        }

        if (null !== $hash) {
            $this->cache->save($hash, $graph);
        }

        return $graph;
    }

    private function computeContainerHash(): ?string
    {
        $environments = ['', '/dev', '/test', '/prod'];
        foreach ($environments as $env) {
            $dir = $this->cacheDir.$env;
            $files = glob($dir.'/*DebugContainer.xml');
            if (false !== $files && [] !== $files) {
                sort($files);
                $path = $files[0];
                $mtime = filemtime($path);
                $size = filesize($path);
                if (false === $mtime || false === $size) {
                    return null;
                }

                return md5($path.':'.$mtime.':'.$size);
            }
        }

        return null;
    }
}
