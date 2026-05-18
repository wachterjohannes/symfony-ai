<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Inspector;

use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphResult;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\Model\ServiceDefinition;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * Service-specific deep dive. Surfaces dependencies, consumers, constructor calls, tags
 * (with attributes), and alias targets — drawing on both the runtime graph and the parsed
 * container DTO so callers get the full picture in a single response.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ServiceInspector implements InspectorInterface
{
    private const NEIGHBOURHOOD_DEPTH = 1;

    public function __construct(
        private readonly ContainerProvider $containerProvider,
        private readonly string $cacheDir,
    ) {
    }

    public function supports(GraphNode $node): bool
    {
        return 'service' === $node->type;
    }

    public function inspect(GraphNode $node, RuntimeGraph $graph, array $include): GraphResult
    {
        $view = $graph->neighbors($node->id, self::NEIGHBOURHOOD_DEPTH, ['depends_on', 'implemented_by']);

        $dependsOnOut = 0;
        $dependsOnIn = 0;
        foreach ($view->edges as $edge) {
            if ('depends_on' === $edge->relation && $edge->from === $node->id) {
                ++$dependsOnOut;
            }
            if ('depends_on' === $edge->relation && $edge->to === $node->id) {
                ++$dependsOnIn;
            }
        }

        $findings = [
            \sprintf(
                'Service has %d direct dependenc%s and %d consumer%s.',
                $dependsOnOut,
                1 === $dependsOnOut ? 'y' : 'ies',
                $dependsOnIn,
                1 === $dependsOnIn ? '' : 's',
            ),
        ];

        $tags = (array) ($node->metadata['tags'] ?? []);
        if ([] !== $tags) {
            $findings[] = 'Tags: '.implode(', ', array_map(static fn ($t) => (string) $t, $tags)).'.';
        }

        $aliasOf = $node->metadata['aliasOf'] ?? null;
        if (\is_string($aliasOf) && '' !== $aliasOf) {
            $findings[] = \sprintf('Alias of "%s".', $aliasOf);
        }

        $definition = $this->loadDefinition($this->serviceIdFromNodeId($node->id));
        if (null !== $definition) {
            $calls = $definition->getCalls();
            if ([] !== $calls) {
                $findings[] = \sprintf('Setter calls: %s.', implode(', ', array_map(static fn (string $m) => $m.'()', $calls)));
            }
            $tagAttrs = [];
            foreach ($definition->getTags() as $tag) {
                $attrs = $tag->getAttributes();
                if ([] !== $attrs) {
                    $tagAttrs[] = $tag->getName().'('.http_build_query($attrs, '', ', ').')';
                }
            }
            if ([] !== $tagAttrs) {
                $findings[] = 'Tag attributes: '.implode('; ', $tagAttrs).'.';
            }
        }

        return new GraphResult(
            summary: \sprintf(
                'Service %s — %d dependencies, %d consumers.',
                $node->label,
                $dependsOnOut,
                $dependsOnIn,
            ),
            primaryNodes: [$this->nodeToArray($node)],
            findings: $findings,
            evidence: $this->edgesToEvidence($view->edges),
            relatedNodes: $this->relatedNodesExcluding($view->nodes, $node->id),
            nextActions: [
                ['tool' => 'symfony-graph', 'args' => ['node' => $node->id, 'depth' => 2]],
                ['tool' => 'symfony-context', 'args' => ['target' => $node->id]],
            ],
        );
    }

    private function serviceIdFromNodeId(string $nodeId): string
    {
        return str_starts_with($nodeId, 'service:') ? substr($nodeId, 8) : $nodeId;
    }

    private function loadDefinition(string $serviceId): ?ServiceDefinition
    {
        $xmlPath = $this->findContainerXml();
        if (null === $xmlPath) {
            return null;
        }

        try {
            $container = $this->containerProvider->getContainer($xmlPath);
        } catch (\Throwable) {
            return null;
        }

        return $container->getServices()[$serviceId] ?? null;
    }

    private function findContainerXml(): ?string
    {
        $environments = ['', '/dev', '/test', '/prod'];
        foreach ($environments as $env) {
            $dir = $this->cacheDir.$env;
            $files = glob($dir.'/*DebugContainer.xml');
            if (false !== $files && [] !== $files) {
                sort($files);

                return $files[0];
            }
        }

        return null;
    }

    /**
     * @param list<\Symfony\AI\Mate\Bridge\Symfony\Graph\GraphEdge> $edges
     *
     * @return list<array{kind: string, from: string, relation: string, to: string}>
     */
    private function edgesToEvidence(array $edges): array
    {
        $rows = [];
        foreach ($edges as $edge) {
            $rows[] = [
                'kind' => 'edge',
                'from' => $edge->from,
                'relation' => $edge->relation,
                'to' => $edge->to,
            ];
        }

        return $rows;
    }

    /**
     * @param list<GraphNode> $nodes
     *
     * @return list<array{id: string, type: string, label: string, metadata: array<string, mixed>}>
     */
    private function relatedNodesExcluding(array $nodes, string $excludeId): array
    {
        $related = [];
        foreach ($nodes as $node) {
            if ($node->id === $excludeId) {
                continue;
            }
            $related[] = $this->nodeToArray($node);
        }

        return $related;
    }

    /**
     * @return array{id: string, type: string, label: string, metadata: array<string, mixed>}
     */
    private function nodeToArray(GraphNode $node): array
    {
        return [
            'id' => $node->id,
            'type' => $node->type,
            'label' => $node->label,
            'metadata' => $node->metadata,
        ];
    }
}
