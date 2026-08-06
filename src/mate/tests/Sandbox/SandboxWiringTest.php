<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Sandbox;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Capability\SandboxTool;
use Symfony\AI\Mate\Sandbox\Exception\CommandNotAllowedException;
use Symfony\AI\Mate\Sandbox\MateApi;
use Symfony\AI\Mate\Sandbox\SandboxRunner;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

/**
 * The allowlist is the whole security story of `$mate`, and it is configuration. If the
 * parameter does not reach MateApi, the sandbox is either dead (empty list) or wide open —
 * both silently.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SandboxWiringTest extends TestCase
{
    public function testTheConfiguredAllowlistReachesTheApi()
    {
        $command = \PHP_BINARY.' -r echo("pong");';

        $api = $this->buildApi(['mate.sandbox.allowed_commands' => [$command]]);

        $this->assertSame('pong', $api->runCommand($command)['output_tail']);
    }

    public function testTheDefaultAllowlistIsEmpty()
    {
        $api = $this->buildApi([]);

        $this->expectException(CommandNotAllowedException::class);
        $this->expectExceptionMessage('currently empty');

        $api->runCommand(\PHP_BINARY.' --version');
    }

    public function testTheRunnerAndToolAreWiredTogether()
    {
        $container = $this->compile([]);

        $this->assertInstanceOf(SandboxRunner::class, $container->get(SandboxRunner::class));
        $this->assertInstanceOf(SandboxTool::class, $container->get(SandboxTool::class));
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function buildApi(array $parameters): MateApi
    {
        $api = $this->compile($parameters)->get(MateApi::class);
        \assert($api instanceof MateApi);

        return $api;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function compile(array $parameters): ContainerBuilder
    {
        $container = new ContainerBuilder();

        (new PhpFileLoader($container, new FileLocator(\dirname(__DIR__, 2).'/src')))->load('default.config.php');

        $container->setParameter('mate.root_dir', sys_get_temp_dir());
        $container->setParameter('mate.extensions', []);
        $container->setParameter('mate.enabled_extensions', []);

        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
        }

        // The sandbox services are private in the real container; the tool is only reachable
        // through ToolInvoker there.
        foreach ([MateApi::class, SandboxRunner::class] as $id) {
            $container->getDefinition($id)->setPublic(true);
        }

        $container->register(SandboxTool::class, SandboxTool::class)
            ->setAutowired(true)
            ->setPublic(true);

        $container->compile();

        return $container;
    }
}
