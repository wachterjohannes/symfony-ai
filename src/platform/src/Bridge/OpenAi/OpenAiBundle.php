<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\OpenAi;

use Symfony\AI\Platform\Platform;
use Symfony\AI\PlatformBundle\AbstractPlatformBridgeBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Registers the OpenAI platform as "openai" (tag "ai.platform"), no FrameworkBundle needed.
 */
final class OpenAiBundle extends AbstractPlatformBridgeBundle
{
    protected string $extensionAlias = 'ai_openai';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->stringNode('api_key')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('region')
                    ->defaultNull()
                    ->validate()
                        ->ifNotInArray([null, 'EU', 'US'])
                        ->thenInvalid('The region must be either "EU" (https://eu.api.openai.com), "US" (https://us.api.openai.com) or null (https://api.openai.com)')
                    ->end()
                    ->info('The region for OpenAI API (EU, US, or null for default)')
                ->end()
                ->stringNode('http_client')
                    ->defaultValue('http_client')
                    ->info('Service ID of the HTTP client to use; a default one is defined when the "http_client" service does not exist')
                ->end()
            ->end();
    }

    /**
     * @param array{api_key: string, region: ?string, http_client: string} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->registerPlatform($builder, 'openai', (new Definition(Platform::class))
            ->setFactory(Factory::class.'::createPlatform')
            ->setArguments([
                '$apiKey' => $config['api_key'],
                '$httpClient' => new Reference($config['http_client']),
                '$region' => $config['region'],
                '$eventDispatcher' => new Reference('event_dispatcher'),
            ]));
    }
}
