<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\PlatformBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAi\OpenAiBundle;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\PlatformBundle\PlatformBundle;
use Symfony\AI\PlatformBundle\Tests\Fixtures\ExposeServicesPass;
use Symfony\AI\PlatformBundle\Tests\Fixtures\TestKernel;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PlatformBundleTest extends TestCase
{
    public function testWeblessContainerCompilesWithOnlyThePlatformAndBridgeBundle()
    {
        $kernel = $this->boot([new PlatformBundle(), new OpenAiBundle()], ['ai_openai' => ['api_key' => 'sk-test']]);

        $container = $kernel->getContainer();
        $this->assertSame(['PlatformBundle' => PlatformBundle::class, 'OpenAiBundle' => OpenAiBundle::class], $container->getParameter('kernel.bundles'));
        $this->assertInstanceOf(PlatformInterface::class, $container->get('ai_platform.platforms')->get('openai'));
    }

    public function testBridgeTagIsPickedUpAndExposedByNameAndByDefaultAlias()
    {
        $container = $this->boot([new PlatformBundle(), new OpenAiBundle()], ['ai_openai' => ['api_key' => 'sk-test']])->getContainer();

        $this->assertTrue($container->get('ai_platform.platforms')->has('openai'));
        $this->assertSame($container->get('ai_platform.platforms')->get('openai'), $container->get(PlatformInterface::class));
    }

    public function testPlatformsWithoutNameAttributeAreRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has no "name" attribute');

        $this->boot([new PlatformBundle()], [], [static function (ContainerBuilder $c): void {
            $c->setDefinition('foo', (new Definition(\stdClass::class))->addTag('ai.platform'));
        }]);
    }

    public function testDuplicatePlatformNamesAreRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('conflicting names');

        $this->boot([new PlatformBundle()], [], [static function (ContainerBuilder $c): void {
            $c->setDefinition('a', (new Definition(\stdClass::class))->addTag('ai.platform', ['name' => 'x']));
            $c->setDefinition('b', (new Definition(\stdClass::class))->addTag('ai.platform', ['name' => 'x']));
        }]);
    }

    public function testUnknownDefaultPlatformIsRejected()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The default platform "nope" is not registered');

        $this->boot([new PlatformBundle(), new OpenAiBundle()], ['ai_platform' => ['default' => 'nope'], 'ai_openai' => ['api_key' => 'sk-test']]);
    }

    public function testDefaultHttpClientIsDefinedWhenAbsent()
    {
        $container = $this->boot([new PlatformBundle(), new OpenAiBundle()], ['ai_openai' => ['api_key' => 'sk-test']])->getContainer();

        $this->assertTrue($container->getParameter('probe.http_client_is_fallback'));
        $this->assertInstanceOf(HttpClientInterface::class, $container->get('http_client'));
    }

    public function testExistingHttpClientServiceWins()
    {
        $container = $this->boot([new PlatformBundle(), new OpenAiBundle()], ['ai_openai' => ['api_key' => 'sk-test']], [static function (ContainerBuilder $c): void {
            $c->setDefinition('http_client', new Definition(MockHttpClient::class));
        }])->getContainer();

        $this->assertFalse($container->getParameter('probe.http_client_is_fallback'));
        $this->assertInstanceOf(MockHttpClient::class, $container->get('http_client'));
    }

    public function testRealFrameworkBundleHttpClientWins()
    {
        $container = $this->boot(
            [new FrameworkBundle(), new PlatformBundle(), new OpenAiBundle()],
            [
                'framework' => ['secret' => 's', 'http_client' => ['enabled' => true], 'http_method_override' => false, 'handle_all_throwables' => true, 'php_errors' => ['log' => true]],
                'ai_openai' => ['api_key' => 'sk-test'],
            ],
        )->getContainer();

        $this->assertFalse($container->getParameter('probe.http_client_is_fallback'));
        $this->assertInstanceOf(PlatformInterface::class, $container->get('ai_platform.platforms')->get('openai'));
    }

    /**
     * @param list<BundleInterface>                  $bundles
     * @param array<string, array<string, mixed>>    $config
     * @param list<callable(ContainerBuilder): void> $callbacks
     */
    private function boot(array $bundles, array $config, array $callbacks = []): Kernel
    {
        $callbacks[] = static function (ContainerBuilder $c): void {
            $c->addCompilerPass(new ExposeServicesPass(), PassConfig::TYPE_BEFORE_REMOVING);
        };

        $kernel = new TestKernel($bundles, $config, $callbacks, uniqid('', true));
        $kernel->boot();

        return $kernel;
    }
}
