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
use Symfony\AI\Mate\Bridge\Symfony\Graph\RequestGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RequestGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ContainerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ProfilerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\CollectorFormatterInterface;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\CollectorRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\DoctrineCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\ExceptionCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\RequestCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\TimeCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RequestGraphFactoryTest extends TestCase
{
    private string $fixturesDir;
    private string $tempCacheDir;
    private ProfilerDataProvider $profilerData;

    protected function setUp(): void
    {
        if (!class_exists(FileProfilerStorage::class)) {
            $this->markTestSkipped('symfony/http-kernel profiler classes not available.');
        }

        $this->fixturesDir = \dirname(__DIR__).'/Fixtures';
        $this->tempCacheDir = sys_get_temp_dir().'/ai_mate_request_graph_factory_'.uniqid();
        mkdir($this->tempCacheDir, 0o777, true);

        $registry = new CollectorRegistry([]);
        foreach ($this->buildFormatters() as $formatter) {
            $registry->register($formatter);
        }
        $this->profilerData = new ProfilerDataProvider($this->fixturesDir.'/profiler', $registry);
    }

    protected function tearDown(): void
    {
        if (!isset($this->tempCacheDir) || !is_dir($this->tempCacheDir)) {
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

    public function testReturnsNullForUnknownToken()
    {
        $factory = $this->buildFactory();

        $this->assertNull($factory->build('does-not-exist'));
    }

    public function testReplaysStaticSliceIntoRequestGraph()
    {
        $factory = $this->buildFactory();

        $graph = $factory->build('abc123');

        $this->assertNotNull($graph);
        // Static slice survives — the container fixture's app.event_listener service node
        // should be present in the merged graph.
        $this->assertNotNull($graph->node('service:app.event_listener'));
        // Request slice was overlaid.
        $this->assertNotNull($graph->node('request:abc123'));
    }

    public function testCachesAcrossBuildsForSameToken()
    {
        $factory = $this->buildFactory();

        $factory->build('abc123');
        $cachePath = $this->tempCacheDir.'/ai_mate/graph/request/abc123.json';
        $this->assertFileExists($cachePath, 'First build should write the request-graph cache file.');

        // Second build reads the cache file — assert by mutating it and verifying we get the mutation back.
        $payload = json_decode((string) file_get_contents($cachePath), true);
        $payload['nodes'][] = [
            'id' => 'service:cache-sentinel',
            'type' => 'service',
            'label' => 'Sentinel',
            'metadata' => [],
        ];
        file_put_contents($cachePath, json_encode($payload));

        $graph = $factory->build('abc123');
        $this->assertNotNull($graph);
        $this->assertNotNull(
            $graph->node('service:cache-sentinel'),
            'Second build should read from cache (sentinel node written via the cache file is visible).',
        );
    }

    /**
     * @return list<CollectorFormatterInterface<\Symfony\Component\HttpKernel\DataCollector\DataCollectorInterface>>
     */
    private function buildFormatters(): array
    {
        // @phpstan-ignore return.type
        return [
            new RequestCollectorFormatter(),
            new DoctrineCollectorFormatter(),
            new ExceptionCollectorFormatter(),
            new TimeCollectorFormatter(),
        ];
    }

    private function buildFactory(): RequestGraphFactory
    {
        $staticFactory = new StaticGraphFactory(
            [new ContainerGraphProvider($this->fixturesDir, new ContainerProvider())],
            new StaticGraphCache($this->tempCacheDir.'/static'),
            $this->fixturesDir,
        );

        $profilerProvider = new ProfilerGraphProvider($this->profilerData);

        return new RequestGraphFactory(
            $staticFactory,
            $profilerProvider,
            new RequestGraphCache($this->tempCacheDir),
            $this->profilerData,
        );
    }
}
