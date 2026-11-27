<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\OpenAi\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAi\OpenAiBundle;
use Symfony\AI\Platform\Bundle\DependencyInjection\Compiler\DefaultServicesPass;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class OpenAiBundleTest extends TestCase
{
    public function testExtensionAliasAndPlatformTag()
    {
        $container = $this->load(['api_key' => 'sk-test', 'region' => 'EU']);

        $this->assertSame('ai_openai', (new OpenAiBundle())->getContainerExtension()->getAlias());

        $definition = $container->getDefinition('ai_platform.platform.openai');
        $this->assertSame([['name' => 'openai']], $definition->getTag('ai.platform'));
        $this->assertSame([['interface' => PlatformInterface::class]], $definition->getTag('proxy'));
        $this->assertTrue($definition->isLazy());
        $this->assertSame('sk-test', $definition->getArgument('$apiKey'));
        $this->assertSame('EU', $definition->getArgument('$region'));
        $this->assertSame('http_client', (string) $definition->getArgument('$httpClient'));
    }

    public function testCustomHttpClientServiceId()
    {
        $container = $this->load(['api_key' => 'sk-test', 'http_client' => 'my.client']);

        $this->assertEquals(new Reference('my.client'), $container->getDefinition('ai_platform.platform.openai')->getArgument('$httpClient'));
    }

    public function testApiKeyIsRequired()
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->load([]);
    }

    public function testInvalidRegionIsRejected()
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('The region must be either');

        $this->load(['api_key' => 'sk-test', 'region' => 'MARS']);
    }

    public function testBuildRegistersTheDefaultServicesPass()
    {
        $container = new ContainerBuilder();
        (new OpenAiBundle())->build($container);

        $passes = array_filter($container->getCompilerPassConfig()->getBeforeOptimizationPasses(), static fn ($pass) => $pass instanceof DefaultServicesPass);
        $this->assertCount(1, $passes);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $bundle = new OpenAiBundle();
        $container->registerExtension($bundle->getContainerExtension());
        $container->loadFromExtension('ai_openai', $config);
        $container->getCompilerPassConfig()->setOptimizationPasses([]);
        $container->getCompilerPassConfig()->setRemovingPasses([]);
        $container->getCompilerPassConfig()->setAfterRemovingPasses([]);
        $container->getCompilerPassConfig()->setBeforeRemovingPasses([]);
        $container->getCompilerPassConfig()->setBeforeOptimizationPasses([]);
        $container->compile();

        return $container;
    }
}
