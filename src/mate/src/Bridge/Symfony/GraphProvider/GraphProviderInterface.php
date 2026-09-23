<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\GraphProvider;

use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphContext;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraphBuilder;

/**
 * Contract for components that contribute nodes and edges to a runtime graph.
 *
 * Implementations are expected to be deterministic and read-only with respect to the
 * target Symfony application's state. Providers should fail gracefully (no-op) when the
 * data source they depend on is unavailable.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface GraphProviderInterface
{
    /**
     * @param GraphContext $context Reserved for request-scoped invocations (profiler graph,
     *                              knowledge graph). Static providers (container, routes) may
     *                              ignore this parameter today; future request-scoped providers
     *                              will set `profilerToken` or `scopes` on it to restrict their
     *                              populate scope.
     */
    public function populate(RuntimeGraphBuilder $graph, GraphContext $context): void;
}
