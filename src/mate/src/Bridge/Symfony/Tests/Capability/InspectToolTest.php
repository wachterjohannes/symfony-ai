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
use Symfony\AI\Mate\Bridge\Symfony\Capability\InspectTool;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ContainerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\RouteGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\InspectorRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\RequestInspector;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\RouteInspector;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\ServiceInspector;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class InspectToolTest extends TestCase
{
    private string $fixturesDir;
    private string $tempCacheDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__).'/Fixtures';
        $this->tempCacheDir = sys_get_temp_dir().'/ai_mate_inspect_tool_'.uniqid();
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

    public function testInspectServiceNode()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->inspect('service:app.event_listener'), DecodeOptions::lenient());

        $this->assertSame('service:app.event_listener', $result['primaryNodes'][0]['id']);
        $this->assertNotEmpty($result['findings']);
    }

    public function testInspectRouteNode()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->inspect('route:app_checkout'), DecodeOptions::lenient());

        $this->assertSame('route:app_checkout', $result['primaryNodes'][0]['id']);
        $this->assertStringContainsString('/checkout', implode(' ', $result['findings']));
    }

    public function testUnknownNodeReturnsWarning()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->inspect('service:does.not.exist'), DecodeOptions::lenient());

        $this->assertSame([], $result['primaryNodes']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testRequestNodeWithoutProfilerFactoryReturnsWarning()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->inspect('request:abc123'), DecodeOptions::lenient());

        $this->assertSame([], $result['primaryNodes']);
        $this->assertNotEmpty($result['warnings']);
    }

    private function buildTool(): InspectTool
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

        return new InspectTool($staticFactory, null, $registry);
    }
}
