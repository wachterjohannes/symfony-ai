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

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A plain HttpKernel (no MicroKernelTrait, no FrameworkBundle) booting the given bundles.
 */
final class TestKernel extends Kernel
{
    /**
     * @param list<BundleInterface>                  $testBundles
     * @param array<string, array<string, mixed>>    $extensionConfig
     * @param list<callable(ContainerBuilder): void> $containerCallbacks
     */
    public function __construct(
        private readonly array $testBundles,
        private readonly array $extensionConfig = [],
        private readonly array $containerCallbacks = [],
        private readonly string $id = '',
    ) {
        parent::__construct('test', true);
    }

    public function registerBundles(): iterable
    {
        return $this->testBundles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container) {
            $container->setParameter('kernel.secret', 'test');
            foreach ($this->extensionConfig as $alias => $config) {
                $container->loadFromExtension($alias, $config);
            }
            foreach ($this->containerCallbacks as $callback) {
                $callback($container);
            }
        });
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/ai-platform-tests/'.$this->id.'/cache';
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir().'/ai-platform-tests/'.$this->id.'/log';
    }

    protected function getContainerClass(): string
    {
        return 'TestContainer'.md5($this->id);
    }
}
