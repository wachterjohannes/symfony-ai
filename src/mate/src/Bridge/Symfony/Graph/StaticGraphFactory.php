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
 * Caches the result via {@see StaticGraphCache}. The cache key is the SHA-1 of the first
 * discovered container XML file. Slightly more expensive than mtime+size but immune to
 * same-second rebuilds and file-system mtime resolution differences across hosts.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class StaticGraphFactory
{
    private readonly ControllerServiceLinker $linker;

    /**
     * @param iterable<GraphProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly StaticGraphCache $cache,
        private readonly string $cacheDir,
        ?ControllerServiceLinker $linker = null,
    ) {
        $this->linker = $linker ?? new ControllerServiceLinker();
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

        // Post-processing step: now that every provider has emitted its nodes, link controller
        // nodes to matching service nodes. Keeping this out of the providers themselves removes
        // any cross-provider ordering dependency.
        $this->linker->link($graph);

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
                $hash = @sha1_file($files[0]);

                return false === $hash ? null : $hash;
            }
        }

        return null;
    }
}
