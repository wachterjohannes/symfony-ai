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
}
