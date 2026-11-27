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

use Symfony\AI\Platform\Bundle\Command\InvokeCommand;
use Symfony\AI\Platform\Bundle\DependencyInjection\Compiler\PlatformRegistryPass;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Core of the AI platform integration. It knows no bridge: bridge bundles tag
 * their platform with "ai.platform" and this bundle exposes them by name.
 *
 * Works without symfony/framework-bundle.
 */
final class PlatformBundle extends AbstractBundle
{
    protected string $extensionAlias = 'ai_platform';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('default')
                    ->defaultNull()
                    ->info('Name of the platform used when none is requested explicitly (defaults to the only one registered)')
                ->end()
            ->end();
    }

    /**
     * @param array{default: ?string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->setParameter('ai_platform.default', $config['default']);

        if (class_exists(Command::class)) {
            $container->services()
                ->set('ai_platform.command.invoke', InvokeCommand::class)
                    ->args([new Reference('ai_platform.platforms'), '%ai_platform.default%'])
                    ->tag('console.command');
        }
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new PlatformRegistryPass());
    }
}
