<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\PlatformBundle\DependencyInjection\Compiler;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Collects every service tagged "ai.platform" (attribute "name") into the
 * "ai_platform.platforms" service locator and registers the per-name autowiring aliases.
 */
final class PlatformRegistryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $refs = [];
        foreach ($container->findTaggedServiceIds('ai.platform') as $id => $tags) {
            $name = $tags[0]['name'] ?? '';
            if ('' === $name) {
                throw new InvalidArgumentException(\sprintf('The service "%s" is tagged "ai.platform" but has no "name" attribute.', $id));
            }
            if (isset($refs[$name])) {
                throw new InvalidArgumentException(\sprintf('The platforms "%s" and "%s" have conflicting names, platform names must be unique.', $refs[$name], $id));
            }
            $refs[$name] = new Reference($id);

            $container->registerAliasForArgument($id, PlatformInterface::class, str_replace('.', '_', $name));
        }

        $container->setAlias('ai_platform.platforms', new Alias((string) ServiceLocatorTagPass::register($container, $refs)))->setPublic(true);

        $default = $container->getParameter('ai_platform.default');
        if (null !== $default && !isset($refs[$default])) {
            throw new InvalidArgumentException(\sprintf('The default platform "%s" is not registered, available: "%s".', $default, implode('", "', array_keys($refs))));
        }

        if (null !== $default) {
            $container->setAlias(PlatformInterface::class, (string) $refs[$default]);
        } elseif (1 === \count($refs)) {
            $container->setAlias(PlatformInterface::class, (string) reset($refs));
        }
    }
}
