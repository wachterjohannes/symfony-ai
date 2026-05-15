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
}
