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
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ContainerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\RouteGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ContextToolTest extends TestCase
{
    private string $fixturesDir;

    private string $tempCacheDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__).'/Fixtures';
        $this->tempCacheDir = sys_get_temp_dir().'/ai_mate_context_tool_'.uniqid();
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

    public function testServiceTargetReturnsEnvelopeWithPrimaryAndRelatedNodes()
    {
        $tool = new ContextTool($this->factory());

        $result = Toon::decode($tool->getContext('service:app.event_listener'), DecodeOptions::lenient());

        $this->assertCount(1, $result['primaryNodes']);
        $this->assertSame('service:app.event_listener', $result['primaryNodes'][0]['id']);
        $this->assertNotEmpty($result['relatedNodes']);
        $this->assertNotEmpty($result['evidence']);
        $this->assertNotEmpty($result['nextActions']);
    }

    public function testRouteTargetIncludesHandledByEdgeInEvidence()
    {
        $tool = new ContextTool($this->factory());

        $result = Toon::decode($tool->getContext('route:app_checkout'), DecodeOptions::lenient());

        $this->assertSame('route:app_checkout', $result['primaryNodes'][0]['id']);
        $found = false;
        foreach ($result['evidence'] as $row) {
            if ('handled_by' === ($row['relation'] ?? null)
                && 'route:app_checkout' === ($row['from'] ?? null)
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function testLatestRequestTargetReturnsWarning()
    {
        $tool = new ContextTool($this->factory());

        $result = Toon::decode($tool->getContext('latest_request'), DecodeOptions::lenient());

        $this->assertSame([], $result['primaryNodes']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testUnknownTargetReturnsWarning()
    {
        $tool = new ContextTool($this->factory());

        $result = Toon::decode($tool->getContext('service:does.not.exist'), DecodeOptions::lenient());

        $this->assertSame([], $result['primaryNodes']);
        $this->assertNotEmpty($result['warnings']);
    }

    public function testStaticTargetReturnsOverview()
    {
        $tool = new ContextTool($this->factory());

        $result = Toon::decode($tool->getContext('static'), DecodeOptions::lenient());

        $this->assertStringContainsString('Static graph', $result['summary']);
        $this->assertNotEmpty($result['relatedNodes']);
    }

    private function factory(): StaticGraphFactory
    {
        return new StaticGraphFactory(
            [
                new ContainerGraphProvider($this->fixturesDir, new ContainerProvider()),
                new RouteGraphProvider($this->fixturesDir),
            ],
            new StaticGraphCache($this->tempCacheDir),
            $this->fixturesDir,
        );
    }
}
