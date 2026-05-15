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
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphEdge;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphCache;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class StaticGraphCacheTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/ai_mate_static_graph_cache_'.uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
    }

    public function testRoundTripsNodesAndEdges()
    {
        $cache = new StaticGraphCache($this->tempDir);

        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A', ['class' => 'A\\Class']));
        $graph->addNode(new GraphNode('service:b', 'service', 'B'));
        $graph->addEdge(new GraphEdge('service:a', 'depends_on', 'service:b'));

        $cache->save('hash-v1', $graph);
        $loaded = $cache->load('hash-v1');

        $this->assertNotNull($loaded);
        $this->assertCount(2, $loaded->nodes());
        $this->assertCount(1, $loaded->edges());
        $this->assertSame('A\\Class', $loaded->node('service:a')?->metadata['class']);
    }

    public function testLoadReturnsNullOnHashMismatch()
    {
        $cache = new StaticGraphCache($this->tempDir);
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A'));
        $cache->save('hash-v1', $graph);

        $this->assertNull($cache->load('hash-v2'));
    }

    public function testLoadReturnsNullWhenFileMissing()
    {
        $cache = new StaticGraphCache($this->tempDir);

        $this->assertNull($cache->load('anything'));
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }
}
