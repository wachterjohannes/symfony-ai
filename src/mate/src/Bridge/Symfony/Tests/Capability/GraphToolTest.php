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
use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphTool;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphContext;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraphBuilder;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ContainerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\GraphProviderInterface;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\RouteGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class GraphToolTest extends TestCase
{
    private string $fixturesDir;

    private string $tempCacheDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__).'/Fixtures';
        $this->tempCacheDir = sys_get_temp_dir().'/ai_mate_graph_tool_'.uniqid();
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

    public function testReturnsStartingNodeOnlyAtDepthZero()
    {
        $tool = new GraphTool($this->factory());

        $result = Toon::decode($tool->getGraph('service:app.event_listener', 0), DecodeOptions::lenient());

        $this->assertCount(1, $result['nodes']);
        $this->assertSame('service:app.event_listener', $result['nodes'][0]['id']);
        $this->assertSame([], $result['edges']);
        $this->assertFalse($result['truncated']);
    }

    public function testReturnsConnectedSubviewAtDefaultDepth()
    {
        $tool = new GraphTool($this->factory());

        $result = Toon::decode($tool->getGraph('service:app.event_listener'), DecodeOptions::lenient());

        $this->assertGreaterThan(1, \count($result['nodes']));
        $this->assertNotEmpty($result['edges']);
    }

    public function testTruncatesWhenMaxNodesReached()
    {
        $tool = new GraphTool($this->factory());

        $result = Toon::decode($tool->getGraph('service:app.event_listener', 2, [], 1), DecodeOptions::lenient());

        $this->assertTrue($result['truncated']);
    }

    public function testFiltersByRelation()
    {
        $tool = new GraphTool($this->factory());

        $result = Toon::decode(
            $tool->getGraph('route:app_event_listener_route', 2, ['handled_by']),
            DecodeOptions::lenient(),
        );

        // depends_on edges should not appear when filtering to handled_by only.
        foreach ($result['edges'] as $edge) {
            $this->assertSame('handled_by', $edge['relation']);
        }
    }

    public function testSuggestedFocusReturnsTopDegreeNodes()
    {
        $provider = new class implements GraphProviderInterface {
            public function populate(RuntimeGraphBuilder $graph, GraphContext $context): void
            {
                $graph->node('service:hub', 'service', 'Hub');
                for ($i = 0; $i < 5; ++$i) {
                    $spokeId = 'service:spoke'.$i;
                    $graph->node($spokeId, 'service', 'Spoke'.$i);
                    $graph->edge('service:hub', 'depends_on', $spokeId);
                }
            }
        };
        $factory = new StaticGraphFactory(
            [$provider],
            new StaticGraphCache($this->tempCacheDir),
            '/non/existent/cache/dir',
        );
        $tool = new GraphTool($factory);

        $result = Toon::decode($tool->getGraph('service:hub', 1), DecodeOptions::lenient());

        $this->assertNotEmpty($result['suggestedFocus']);
        $this->assertSame('service:hub', $result['suggestedFocus'][0]);
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
