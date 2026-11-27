<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests\Bundle\Fixtures;

use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Makes the services under test public and records whether "http_client" is the bundle's fallback.
 */
final class ExposeServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->setParameter('probe.http_client_is_fallback', $container->hasDefinition('http_client') && $container->getDefinition('http_client')->hasTag('ai_platform.default_service'));

        foreach (['http_client', PlatformInterface::class, 'ai_platform.platform.openai'] as $id) {
            if ($container->hasDefinition($id)) {
                $container->getDefinition($id)->setPublic(true);
            } elseif ($container->hasAlias($id)) {
                $container->getAlias($id)->setPublic(true);
            }
        }
    }
}
