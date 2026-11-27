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

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Defines minimal fallbacks for the well-known service ids platforms depend on, but only when
 * nobody else (typically FrameworkBundle) has registered them. This is what lets a platform
 * run in an application without symfony/framework-bundle.
 */
final class DefaultServicesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has('http_client') && interface_exists(HttpClientInterface::class) && class_exists(HttpClient::class)) {
            $container->setDefinition('http_client', (new Definition(HttpClientInterface::class))
                ->setFactory([HttpClient::class, 'create'])
                ->addTag('ai_platform.default_service'));
            $container->setAlias(HttpClientInterface::class, 'http_client');
        }

        if (!$container->has('event_dispatcher') && class_exists(EventDispatcher::class)) {
            $container->setDefinition('event_dispatcher', (new Definition(EventDispatcher::class))
                ->addTag('ai_platform.default_service'));
            if (!$container->has(EventDispatcherInterface::class)) {
                $container->setAlias(EventDispatcherInterface::class, 'event_dispatcher');
            }
        }
    }
}
