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
 * Post-processes an assembled {@see RuntimeGraph} to connect controller nodes to the service
 * nodes whose `metadata.class` matches the controller's FQCN.
 *
 * This step used to live inside {@see \Symfony\AI\Mate\Bridge\Symfony\GraphProvider\RouteGraphProvider},
 * which made the resulting graph dependent on provider iteration order (controllers had to be
 * emitted after services). Extracting it removes the ordering constraint and lets each
 * provider stay focused on a single data source.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class ControllerServiceLinker
{
    public function link(RuntimeGraph $graph): void
    {
        /** @var array<string, list<string>> $classToServiceIds */
        $classToServiceIds = [];
        foreach ($graph->nodes() as $node) {
            if ('service' !== $node->type) {
                continue;
            }
            $class = $node->metadata['class'] ?? null;
            if (\is_string($class) && '' !== $class) {
                $classToServiceIds[$class][] = $node->id;
            }
        }

        foreach ($graph->nodes() as $node) {
            if ('controller' !== $node->type) {
                continue;
            }
            $class = $node->metadata['class'] ?? null;
            if (!\is_string($class) || !isset($classToServiceIds[$class])) {
                continue;
            }
            foreach ($classToServiceIds[$class] as $serviceId) {
                $graph->addEdge(new GraphEdge($node->id, 'depends_on', $serviceId));
            }
        }
    }
}
