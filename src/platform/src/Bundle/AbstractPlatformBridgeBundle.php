<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bundle;

use Symfony\AI\Platform\Bundle\DependencyInjection\Compiler\DefaultServicesPass;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Convention for platform bridge bundles: a bridge bundle only describes its config tree and calls
 * {@see registerPlatform()}; tagging and default services (HTTP client, event dispatcher) are shared.
 */
abstract class AbstractPlatformBridgeBundle extends AbstractBundle
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        // Idempotent, so every bridge bundle can add it.
        $container->addCompilerPass(new DefaultServicesPass());
    }

    /**
     * Registers the platform as "ai_platform.platform.<name>", tagged "ai.platform" so PlatformBundle finds it.
     *
     * @param non-empty-string $name public platform name (the "name" attribute of the tag)
     */
    protected function registerPlatform(ContainerBuilder $container, string $name, Definition $definition): void
    {
        $definition
            ->setLazy(true)
            ->addTag('proxy', ['interface' => PlatformInterface::class])
            ->addTag('ai.platform', ['name' => $name]);

        $container->setDefinition('ai_platform.platform.'.$name, $definition);
    }

    protected static function httpClientReference(?string $httpClientId): Reference
    {
        return new Reference($httpClientId ?? 'http_client');
    }
}
