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
 * Contributes route and controller nodes plus handled_by edges to the runtime graph.
 *
 * Reads Symfony's compiled `url_generating_routes.php` cache file (one of two files the
 * RouterCacheWarmer dumps into var/cache/<env>/). The file returns an array keyed by route
 * name; each value is a tuple [variables, defaults, requirements, tokens, hostTokens, methods,
 * schemes] where defaults contains the `_controller` callable string.
 *
 * When the static graph already contains a `service:<id>` node whose metadata.class matches
 * the controller's FQCN, a best-effort `depends_on` edge is emitted between the controller
 * and that service.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RouteGraphProvider implements GraphProviderInterface
{
    public function __construct(
        private readonly string $cacheDir,
    ) {
    }

    public function populate(RuntimeGraphBuilder $graph, GraphContext $context): void
    {
        $routerCachePath = $this->findRouterCache();
        if (null === $routerCachePath) {
            return;
        }

        $routes = $this->loadRoutes($routerCachePath);
        if (null === $routes) {
            return;
        }

        $classToServiceNodeId = $this->collectServiceClassIndex($graph);

        foreach ($routes as $name => $tuple) {
            if (!\is_string($name) || !\is_array($tuple)) {
                continue;
            }

            $defaults = $tuple[1] ?? [];
            if (!\is_array($defaults) || !isset($defaults['_controller']) || !\is_string($defaults['_controller'])) {
                continue;
            }

            $controller = $defaults['_controller'];
            $path = $this->reconstructPath($tuple[3] ?? []);
            $methods = \is_array($tuple[5] ?? null) ? array_values($tuple[5]) : [];

            $routeNodeId = 'route:'.$name;
            $graph->node($routeNodeId, 'route', $name, [
                'path' => $path,
                'methods' => $methods,
                'defaults' => $defaults,
            ]);

            [$controllerNodeId, $controllerClass, $controllerLabel] = $this->parseController($controller);

            $graph->node($controllerNodeId, 'controller', $controllerLabel, [
                'class' => $controllerClass,
            ]);
            $graph->edge($routeNodeId, 'handled_by', $controllerNodeId);

            if (isset($classToServiceNodeId[$controllerClass])) {
                $graph->edge($controllerNodeId, 'depends_on', $classToServiceNodeId[$controllerClass]);
            }
        }
    }

    private function findRouterCache(): ?string
    {
        $environments = ['', '/dev', '/test', '/prod'];
        foreach ($environments as $env) {
            $candidate = $this->cacheDir.$env.'/url_generating_routes.php';
            if (file_exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, array<int, mixed>>|null
     */
    private function loadRoutes(string $path): ?array
    {
        $loader = static fn (string $file): mixed => require $file;
        $result = $loader($path);

        if (!\is_array($result)) {
            return null;
        }

        /** @var array<string, array<int, mixed>> $result */
        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function collectServiceClassIndex(RuntimeGraphBuilder $graph): array
    {
        $index = [];
        foreach ($graph->graph()->nodes() as $node) {
            if ('service' !== $node->type) {
                continue;
            }
            $class = $node->metadata['class'] ?? null;
            if (\is_string($class) && '' !== $class) {
                $index[$class] = $node->id;
            }
        }

        return $index;
    }

    /**
     * @param array<int, mixed> $tokens
     */
    private function reconstructPath(array $tokens): string
    {
        $segments = [];
        foreach (array_reverse($tokens) as $token) {
            if (!\is_array($token)) {
                continue;
            }
            $type = $token[0] ?? null;
            $value = $token[1] ?? null;
            if (!\is_string($type) || !\is_string($value)) {
                continue;
            }
            if ('text' === $type) {
                $segments[] = $value;
            } elseif ('variable' === $type) {
                $segments[] = $value.'{'.($token[3] ?? '').'}';
            }
        }

        $path = implode('', $segments);

        return '' === $path ? '/' : $path;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    private function parseController(string $controller): array
    {
        if (str_contains($controller, '::')) {
            [$class, $method] = explode('::', $controller, 2);
            $shortClass = substr((string) strrchr($class, '\\'), 1) ?: $class;

            return [
                'controller:'.$class.'::'.$method,
                $class,
                $shortClass.'::'.$method,
            ];
        }

        $class = $controller;
        $shortClass = substr((string) strrchr($class, '\\'), 1) ?: $class;

        return [
            'controller:'.$class.'::__invoke',
            $class,
            $shortClass.'::__invoke',
        ];
    }
}
