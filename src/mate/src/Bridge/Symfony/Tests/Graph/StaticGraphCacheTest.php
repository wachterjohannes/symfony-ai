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
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\Exception\GraphProviderException;

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

    public function testLoadReturnsNullOnCorruptJson()
    {
        $cacheFile = $this->tempDir.'/ai_mate/graph/static.json';
        mkdir(\dirname($cacheFile), 0o777, true);
        file_put_contents($cacheFile, '{this is not valid json');

        $cache = new StaticGraphCache($this->tempDir);

        $this->assertNull($cache->load('anything'));
    }

    public function testSaveThrowsOnUnwritableTarget()
    {
        if (\function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            $this->markTestSkipped('Running as root bypasses POSIX mode bits.');
        }

        $readonlyDir = $this->tempDir.'/readonly';
        mkdir($readonlyDir, 0o777, true);
        chmod($readonlyDir, 0o555);

        try {
            $cache = new StaticGraphCache($readonlyDir);
            $graph = new RuntimeGraph();
            $graph->addNode(new GraphNode('service:a', 'service', 'A'));

            $this->expectException(GraphProviderException::class);
            $cache->save('hash-v1', $graph);
        } finally {
            chmod($readonlyDir, 0o777);
        }
    }

    public function testRoundTripsTagMetadata()
    {
        $cache = new StaticGraphCache($this->tempDir);

        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A', [
            'class' => 'A\\Class',
            'tags' => ['kernel.event_listener', 'doctrine.event_listener'],
            'tagMetadata' => [
                ['name' => 'kernel.event_listener', 'attributes' => ['event' => 'kernel.request', 'method' => 'onKernelRequest']],
                ['name' => 'doctrine.event_listener', 'attributes' => ['event' => 'postPersist']],
            ],
        ]));

        $cache->save('hash-v1', $graph);
        $loaded = $cache->load('hash-v1');

        $this->assertNotNull($loaded);
        $node = $loaded->node('service:a');
        $this->assertNotNull($node);
        $this->assertSame(['kernel.event_listener', 'doctrine.event_listener'], $node->metadata['tags']);
        $this->assertSame('kernel.request', $node->metadata['tagMetadata'][0]['attributes']['event']);
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
