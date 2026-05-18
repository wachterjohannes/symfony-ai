<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\AI\Mate\Bridge\Symfony\Capability\ContextTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\InspectTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\ProfilerResourceTemplate;
use Symfony\AI\Mate\Bridge\Symfony\Capability\ProfilerTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\ServiceTool;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RequestGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RequestGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ContainerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ProfilerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\RouteGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\InspectorRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\RequestInspector;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\RouteInspector;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\ServiceInspector;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\CollectorRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\DoctrineCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\ExceptionCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\MailerCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\RequestCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\TimeCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\TranslationCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Profiler\Profile;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $configurator) {
    // Parameters
    $configurator->parameters()
        ->set('ai_mate_symfony.cache_dir', '%mate.root_dir%/var/cache');

    $services = $configurator->services();

    // Container introspection services (always available)
    $services->set(ContainerProvider::class);

    $services->set(ServiceTool::class)
        ->args([
            '%ai_mate_symfony.cache_dir%',
            service(ContainerProvider::class),
        ]);

    // Runtime graph layer (static slice — routes + container).
    $services->set(ContainerGraphProvider::class)
        ->args([
            '%ai_mate_symfony.cache_dir%',
            service(ContainerProvider::class),
        ])
        ->tag('ai_mate.graph_provider');

    $services->set(RouteGraphProvider::class)
        ->args(['%ai_mate_symfony.cache_dir%'])
        ->tag('ai_mate.graph_provider');

    $services->set(StaticGraphCache::class)
        ->args(['%ai_mate_symfony.cache_dir%']);

    $services->set(StaticGraphFactory::class)
        ->args([
            tagged_iterator('ai_mate.graph_provider'),
            service(StaticGraphCache::class),
            '%ai_mate_symfony.cache_dir%',
        ]);

    $services->set(ContextTool::class)
        ->args([
            service(StaticGraphFactory::class),
            service(RequestGraphFactory::class)->nullOnInvalid(),
            service(ProfilerDataProvider::class)->nullOnInvalid(),
        ]);

    $services->set(GraphTool::class)
        ->args([service(StaticGraphFactory::class)]);

    // Inspectors — always available; service/route inspectors don't need the profiler.
    $services->set(ServiceInspector::class)
        ->args([service(ContainerProvider::class), '%ai_mate_symfony.cache_dir%'])
        ->tag('ai_mate.graph_inspector');

    $services->set(RouteInspector::class)
        ->tag('ai_mate.graph_inspector');

    $services->set(InspectorRegistry::class)
        ->args([tagged_iterator('ai_mate.graph_inspector')]);

    $services->set(InspectTool::class)
        ->args([
            service(StaticGraphFactory::class),
            service(RequestGraphFactory::class)->nullOnInvalid(),
            service(InspectorRegistry::class),
        ]);

    // Profiler services (optional - only if profiler classes are available)
    if (class_exists(Profile::class)) {
        $configurator->parameters()
            ->set('ai_mate_symfony.profiler_dir', '%mate.root_dir%/var/cache/dev/profiler');

        $services->set(CollectorRegistry::class)
            ->args([tagged_iterator('ai_mate.profiler_collector_formatter')]);

        $services->set(ProfilerDataProvider::class)
            ->args([
                '%ai_mate_symfony.profiler_dir%',
                service(CollectorRegistry::class),
            ]);

        // Built-in collector formatters
        $services->set(RequestCollectorFormatter::class)
            ->lazy()
            ->tag('ai_mate.profiler_collector_formatter');

        $services->set(ExceptionCollectorFormatter::class)
            ->lazy()
            ->tag('ai_mate.profiler_collector_formatter');

        $services->set(MailerCollectorFormatter::class)
            ->lazy()
            ->tag('ai_mate.profiler_collector_formatter');

        $services->set(TranslationCollectorFormatter::class)
            ->lazy()
            ->tag('ai_mate.profiler_collector_formatter');

        $services->set(DoctrineCollectorFormatter::class)
            ->lazy()
            ->tag('ai_mate.profiler_collector_formatter');

        $services->set(TimeCollectorFormatter::class)
            ->lazy()
            ->tag('ai_mate.profiler_collector_formatter');

        // MCP Capabilities
        $services->set(ProfilerTool::class)
            ->args([service(ProfilerDataProvider::class)]);

        $services->set(ProfilerResourceTemplate::class)
            ->args([service(ProfilerDataProvider::class)]);

        // Request-scoped graph layer — only available when the profiler is loaded.
        // ProfilerGraphProvider is NOT tagged ai_mate.graph_provider because it must only
        // run on the per-token RequestGraphFactory path, never on the static graph build.
        $services->set(ProfilerGraphProvider::class)
            ->args([service(ProfilerDataProvider::class)]);

        $services->set(RequestGraphCache::class)
            ->args(['%ai_mate_symfony.cache_dir%']);

        $services->set(RequestGraphFactory::class)
            ->args([
                service(StaticGraphFactory::class),
                service(ProfilerGraphProvider::class),
                service(RequestGraphCache::class),
                service(ProfilerDataProvider::class),
            ]);

        $services->set(RequestInspector::class)
            ->tag('ai_mate.graph_inspector');
    }
};
