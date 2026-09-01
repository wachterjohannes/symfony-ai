<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphContext;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraphBuilder;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\GraphProviderInterface;

/**
 * Counts populate() invocations for cache-hit assertions.
 */
final class CountingGraphProvider implements GraphProviderInterface
{
    public int $calls = 0;

    public function populate(RuntimeGraphBuilder $graph, GraphContext $context): void
    {
        ++$this->calls;
        $graph->node('service:stub', 'service', 'Stub', ['class' => 'Stub\\Class']);
        $graph->node('service:other', 'service', 'Other');
        $graph->edge('service:stub', 'depends_on', 'service:other');
    }
}

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class StaticGraphFactoryTest extends TestCase
{
    private string $fixturesDir;

    private string $tempCacheDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__).'/Fixtures';
        $this->tempCacheDir = sys_get_temp_dir().'/ai_mate_static_graph_factory_'.uniqid();
        mkdir($this->tempCacheDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempCacheDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempCacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tempCacheDir);
    }

    public function testBuildsGraphFromProviders()
    {
        $provider = new CountingGraphProvider();
        $factory = new StaticGraphFactory(
            [$provider],
            new StaticGraphCache($this->tempCacheDir),
            $this->fixturesDir,
        );

        $graph = $factory->build();

        $this->assertGreaterThan(0, \count($graph->nodes()));
        $this->assertSame(1, $provider->calls);
    }

    public function testCachesAcrossBuildsWhenContainerUnchanged()
    {
        $provider = new CountingGraphProvider();
        $cache = new StaticGraphCache($this->tempCacheDir);
        $factory = new StaticGraphFactory([$provider], $cache, $this->fixturesDir);

        $factory->build();
        $factory->build();
        $factory->build();

        $this->assertSame(1, $provider->calls, 'Providers should only run on the first build.');
    }

    public function testBuildsAnewWhenNoCacheAvailable()
    {
        $provider = new CountingGraphProvider();
        $factory = new StaticGraphFactory(
            [$provider],
            new StaticGraphCache($this->tempCacheDir),
            '/non/existent/cache/dir',
        );

        $graph = $factory->build();

        // No container XML found means no hash, so cache is bypassed but the build still runs.
        $this->assertSame(1, $provider->calls);
        $this->assertGreaterThan(0, \count($graph->nodes()));
    }

    public function testLinkerRunsAfterProviders()
    {
        $provider = new class implements GraphProviderInterface {
            public function populate(RuntimeGraphBuilder $graph, GraphContext $context): void
            {
                // Emit controller first, service second — proves the linker doesn't care about order.
                $graph->node('controller:App\\Controller\\X::run', 'controller', 'X::run', ['class' => 'App\\Controller\\X']);
                $graph->node('service:app.controller.x', 'service', 'X', ['class' => 'App\\Controller\\X']);
            }
        };

        $factory = new StaticGraphFactory(
            [$provider],
            new StaticGraphCache($this->tempCacheDir),
            $this->fixturesDir,
        );

        $graph = $factory->build();

        $found = false;
        foreach ($graph->edges() as $edge) {
            if ('controller:App\\Controller\\X::run' === $edge->from
                && 'depends_on' === $edge->relation
                && 'service:app.controller.x' === $edge->to
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected ControllerServiceLinker to emit depends_on edge after providers ran.');
    }

    public function testCacheInvalidatedWhenContainerContentChanges()
    {
        $containerDir = $this->tempCacheDir.'/container';
        mkdir($containerDir, 0o777, true);
        $containerXml = $containerDir.'/App_KernelDevDebugContainer.xml';
        file_put_contents($containerXml, "<?xml version=\"1.0\"?>\n<container><services/></container>\n");

        $provider = new CountingGraphProvider();
        $cache = new StaticGraphCache($this->tempCacheDir.'/cache');
        $factory = new StaticGraphFactory([$provider], $cache, $containerDir);

        $factory->build();
        $factory->build();
        $this->assertSame(1, $provider->calls, 'Second build should hit the cache.');

        // Mutate the container XML — sha1 changes, cache should miss, provider re-runs.
        file_put_contents($containerXml, "<?xml version=\"1.0\"?>\n<container><services><!-- changed --></services></container>\n");
        $factory->build();

        $this->assertSame(2, $provider->calls);
    }
}
