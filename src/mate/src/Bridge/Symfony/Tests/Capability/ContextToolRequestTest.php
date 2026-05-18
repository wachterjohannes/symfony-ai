<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Capability;

use HelgeSverre\Toon\DecodeOptions;
use HelgeSverre\Toon\Toon;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Capability\ContextTool;
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
final class ContextToolRequestTest extends TestCase
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
        $this->tempCacheDir = sys_get_temp_dir().'/ai_mate_context_request_'.uniqid();
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

    public function testLatestRequestResolvesToNewestProfile()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->getContext('latest_request'), DecodeOptions::lenient());

        $this->assertCount(1, $result['primaryNodes']);
        // ghi789 has the highest `time` in the fixture (1704449000) → newest.
        $this->assertSame('request:ghi789', $result['primaryNodes'][0]['id']);
    }

    public function testBareRequestAliasesToLatestRequest()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->getContext('request'), DecodeOptions::lenient());

        $this->assertCount(1, $result['primaryNodes']);
        $this->assertSame('request:ghi789', $result['primaryNodes'][0]['id']);
    }

    public function testExplicitRequestTargetReturnsEnvelope()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->getContext('request:abc123'), DecodeOptions::lenient());

        $this->assertSame('request:abc123', $result['primaryNodes'][0]['id']);
        $this->assertNotEmpty($result['findings']);
        // No DB collector in this fixture profile, so the "no queries" finding fires.
        $this->assertStringContainsString('No database queries recorded', implode(' ', $result['findings']));
    }

    public function testUnknownTokenReturnsWarning()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->getContext('request:does-not-exist'), DecodeOptions::lenient());

        $this->assertSame([], $result['primaryNodes']);
        $this->assertNotEmpty($result['warnings']);
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

    private function buildTool(): ContextTool
    {
        $staticFactory = new StaticGraphFactory(
            [new ContainerGraphProvider($this->fixturesDir, new ContainerProvider())],
            new StaticGraphCache($this->tempCacheDir.'/static'),
            $this->fixturesDir,
        );
        $requestFactory = new RequestGraphFactory(
            $staticFactory,
            new ProfilerGraphProvider($this->profilerData),
            new RequestGraphCache($this->tempCacheDir),
            $this->profilerData,
        );

        return new ContextTool($staticFactory, $requestFactory, $this->profilerData);
    }
}
