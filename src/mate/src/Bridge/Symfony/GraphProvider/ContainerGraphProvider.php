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
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * Contributes service / interface / alias nodes plus depends_on / implemented_by edges to
 * the runtime graph by consuming the parsed container DTO produced by {@see ContainerProvider}.
 *
 * No XML parsing happens here — this provider only walks the in-memory model. Cache-dir
 * discovery mirrors the strategy already in {@see \Symfony\AI\Mate\Bridge\Symfony\Capability\ServiceTool}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ContainerGraphProvider implements GraphProviderInterface
{
    public function __construct(
        private readonly string $cacheDir,
        private readonly ContainerProvider $provider,
    ) {
    }

    public function populate(RuntimeGraphBuilder $graph, GraphContext $context): void
    {
        $containerXmlPath = $this->findContainerXml();
        if (null === $containerXmlPath) {
            return;
        }

        $container = $this->provider->getContainer($containerXmlPath);
        $services = $container->getServices();

        foreach ($services as $service) {
            $serviceId = $service->getId();
            $serviceNodeId = 'service:'.$serviceId;
            $class = $service->getClass();

            $tagNames = [];
            $tagMetadata = [];
            foreach ($service->getTags() as $tag) {
                $tagNames[] = $tag->getName();
                $tagMetadata[] = [
                    'name' => $tag->getName(),
                    'attributes' => $tag->getAttributes(),
                ];
            }

            $metadata = [
                'class' => $class,
                'tags' => $tagNames,
                'tagMetadata' => $tagMetadata,
            ];

            $alias = $service->getAlias();
            if (null !== $alias) {
                $metadata['aliasOf'] = $alias;
            }

            $graph->node($serviceNodeId, 'service', $class ?? $serviceId, $metadata);

            // Service-to-service dependencies via constructor arguments.
            foreach ($service->getArguments() as $dependencyId) {
                $dependencyNodeId = 'service:'.$dependencyId;
                $graph->edge($serviceNodeId, 'depends_on', $dependencyNodeId);
            }

            // Interface → service edges when the service class is registered as an interface.
            // Pass `false` to interface_exists to skip autoload — we only want to match interfaces
            // that have already been resolved by the surrounding application.
            if (null !== $class && interface_exists($class, false)) {
                $graph->node('interface:'.$class, 'interface', $class);
                $graph->edge('interface:'.$class, 'implemented_by', $serviceNodeId);
            }
        }
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
}
