<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Operation\Recipe;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Capability\ContextTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\InspectTool;
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
use Symfony\AI\Mate\Bridge\Symfony\Operation\GraphToolBox;
use Symfony\AI\Mate\Bridge\Symfony\Operation\Recipe\InvestigateExceptionRecipe;
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
final class InvestigateExceptionRecipeTest extends TestCase
{
    private string $fixturesDir;
    private string $tempCacheDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__, 2).'/Fixtures';
        $this->tempCacheDir = sys_get_temp_dir().'/ai_mate_recipe_exception_'.uniqid();
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

    public function testHasStableName()
    {
        $this->assertSame('investigate_exception', (new InvestigateExceptionRecipe())->name());
    }

    public function testReportsNoExceptionForRequestWithoutOne()
    {
        if (!class_exists(FileProfilerStorage::class)) {
            $this->markTestSkipped('symfony/http-kernel profiler classes not available.');
        }

        $toolBox = $this->buildToolBoxWithProfiler();

        $result = (new InvestigateExceptionRecipe())->run(['target' => 'request:abc123'], $toolBox);

        // The fixture profile abc123 has no exception collector — recipe must say so.
        $findingText = implode(' ', $result->findings);
        $this->assertStringContainsString('No exception edge', $findingText);
        $this->assertSame('request:abc123', $result->primaryNodes[0]['id'] ?? null);
    }

    public function testPropagatesProfilerUnavailableWarning()
    {
        $staticFactory = new StaticGraphFactory(
            [
                new ContainerGraphProvider($this->fixturesDir, new ContainerProvider()),
                new RouteGraphProvider($this->fixturesDir),
            ],
            new StaticGraphCache($this->tempCacheDir),
            $this->fixturesDir,
        );
        $registry = new InspectorRegistry([
            new ServiceInspector(new ContainerProvider(), $this->fixturesDir),
            new RouteInspector(),
            new RequestInspector(),
        ]);
        $toolBox = new GraphToolBox(
            new ContextTool($staticFactory, null, null),
            new GraphTool($staticFactory),
            new InspectTool($staticFactory, null, $registry),
        );

        $result = (new InvestigateExceptionRecipe())->run([], $toolBox);

        $this->assertSame([], $result->primaryNodes);
        $this->assertNotEmpty($result->warnings);
    }

    private function buildToolBoxWithProfiler(): GraphToolBox
    {
        $profilerData = new ProfilerDataProvider($this->fixturesDir.'/profiler', $this->buildCollectorRegistry());
        $staticFactory = new StaticGraphFactory(
            [
                new ContainerGraphProvider($this->fixturesDir, new ContainerProvider()),
                new RouteGraphProvider($this->fixturesDir),
            ],
            new StaticGraphCache($this->tempCacheDir.'/static'),
            $this->fixturesDir,
        );
        $requestFactory = new RequestGraphFactory(
            $staticFactory,
            new ProfilerGraphProvider($profilerData),
            new RequestGraphCache($this->tempCacheDir),
            $profilerData,
        );
        $inspectorRegistry = new InspectorRegistry([
            new ServiceInspector(new ContainerProvider(), $this->fixturesDir),
            new RouteInspector(),
            new RequestInspector(),
        ]);

        return new GraphToolBox(
            new ContextTool($staticFactory, $requestFactory, $profilerData),
            new GraphTool($staticFactory),
            new InspectTool($staticFactory, $requestFactory, $inspectorRegistry),
        );
    }

    private function buildCollectorRegistry(): CollectorRegistry
    {
        $registry = new CollectorRegistry([]);
        foreach ($this->buildFormatters() as $formatter) {
            $registry->register($formatter);
        }

        return $registry;
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
}
