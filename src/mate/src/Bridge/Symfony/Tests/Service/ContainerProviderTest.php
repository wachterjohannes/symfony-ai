<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ContainerProviderTest extends TestCase
{
    public function testParsesServiceArguments()
    {
        $provider = new ContainerProvider();
        $container = $provider->getContainer(
            \dirname(__DIR__).'/Fixtures/App_KernelDevDebugContainer.xml',
        );

        $services = $container->getServices();

        $this->assertArrayHasKey('app.event_listener', $services);
        $this->assertSame(
            ['event_dispatcher', 'logger'],
            $services['app.event_listener']->getArguments(),
        );
    }

    public function testServicesWithoutArgumentsHaveEmptyList()
    {
        $provider = new ContainerProvider();
        $container = $provider->getContainer(
            \dirname(__DIR__).'/Fixtures/App_KernelDevDebugContainer.xml',
        );

        $services = $container->getServices();

        $this->assertArrayHasKey('cache.app', $services);
        $this->assertSame([], $services['cache.app']->getArguments());
    }

    public function testAliasCopiesArgumentsFromTarget()
    {
        $provider = new ContainerProvider();
        $container = $provider->getContainer(
            \dirname(__DIR__).'/Fixtures/App_KernelDevDebugContainer.xml',
        );

        $services = $container->getServices();

        // my_service aliases cache.app, which has no arguments
        $this->assertArrayHasKey('my_service', $services);
        $this->assertSame([], $services['my_service']->getArguments());
    }

    public function testAliasPreservesAliasIdAfterResolution()
    {
        $provider = new ContainerProvider();
        $container = $provider->getContainer(
            \dirname(__DIR__).'/Fixtures/App_KernelDevDebugContainer.xml',
        );

        $services = $container->getServices();

        $this->assertArrayHasKey('my_service', $services);
        $this->assertSame('cache.app', $services['my_service']->getAlias());
        $this->assertSame('Symfony\Component\Cache\Adapter\FilesystemAdapter', $services['my_service']->getClass());
    }

    public function testAliasChainPointsAtImmediateTargetOnly()
    {
        $provider = new ContainerProvider();
        $container = $provider->getContainer(
            \dirname(__DIR__).'/Fixtures/App_KernelDevDebugContainer.xml',
        );

        $services = $container->getServices();

        // Single-hop resolution: the outer alias points at its declared target
        // (alias_chain_middle), not transitively at cache.app. This documents
        // current behaviour rather than asserting deep-chain resolution.
        $this->assertArrayHasKey('alias_chain_outer', $services);
        $this->assertSame('alias_chain_middle', $services['alias_chain_outer']->getAlias());
        $this->assertArrayHasKey('alias_chain_middle', $services);
        $this->assertSame('cache.app', $services['alias_chain_middle']->getAlias());
    }
}
