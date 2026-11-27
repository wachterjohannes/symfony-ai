<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App;

use Symfony\AI\Platform\Bridge\OpenAi\OpenAiBundle;
use Symfony\AI\PlatformBundle\PlatformBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * Plain HttpKernel: no MicroKernelTrait, no FrameworkBundle.
 */
final class Kernel extends BaseKernel
{
    public function registerBundles(): iterable
    {
        yield new PlatformBundle();
        yield new OpenAiBundle();
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__.'/../config/config.yaml');
    }

    public function getProjectDir(): string
    {
        return \dirname(__DIR__);
    }

    protected function build(ContainerBuilder $container): void
    {
        // FrameworkBundle normally registers this pass; without it nobody turns "console.command" tags into commands.
        $container->addCompilerPass(new AddConsoleCommandPass());

        if ('1' === ($_SERVER['DEMO_MOCK_HTTP'] ?? '')) {
            // Same override a framework app gets for free: an existing "http_client" service wins over the bundle's fallback.
            $container->setDefinition('http_client', (new Definition(MockHttpClient::class))
                ->setFactory([MockResponses::class, 'client']));
        }
    }
}
