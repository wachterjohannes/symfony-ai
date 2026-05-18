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
use Symfony\AI\Mate\Bridge\Symfony\Graph\RequestGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\Exception\GraphProviderException;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RequestGraphCacheTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/ai_mate_request_graph_cache_'.uniqid();
        mkdir($this->tempDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->tempDir);
    }

    public function testRoundTripsNodesAndEdges()
    {
        $cache = new RequestGraphCache($this->tempDir);

        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('request:tok1', 'request', 'GET /api → 200', [
            'token' => 'tok1',
            'method' => 'GET',
            'status_code' => 200,
        ]));
        $graph->addNode(new GraphNode('query:tok1:0', 'query', 'SELECT 1', [
            'sql' => 'SELECT 1',
            'total_time_ms' => 1.5,
        ]));
        $graph->addEdge(new GraphEdge('request:tok1', 'executed_query', 'query:tok1:0'));

        $cache->save('tok1', $graph);
        $loaded = $cache->load('tok1');

        $this->assertNotNull($loaded);
        $this->assertCount(2, $loaded->nodes());
        $this->assertCount(1, $loaded->edges());
        $this->assertSame('GET', $loaded->node('request:tok1')?->metadata['method']);
    }

    public function testLoadReturnsNullOnMismatchedToken()
    {
        $cache = new RequestGraphCache($this->tempDir);
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('request:tok1', 'request', 'R'));
        $cache->save('tok1', $graph);

        $this->assertNull($cache->load('different-token'));
    }

    public function testLoadReturnsNullWhenFileMissing()
    {
        $cache = new RequestGraphCache($this->tempDir);

        $this->assertNull($cache->load('never-saved'));
    }

    public function testLoadReturnsNullOnCorruptJson()
    {
        $cacheFile = $this->tempDir.'/ai_mate/graph/request/corrupt.json';
        mkdir(\dirname($cacheFile), 0o777, true);
        file_put_contents($cacheFile, '{this is not valid json');

        $cache = new RequestGraphCache($this->tempDir);

        $this->assertNull($cache->load('corrupt'));
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
            $cache = new RequestGraphCache($readonlyDir);
            $graph = new RuntimeGraph();
            $graph->addNode(new GraphNode('request:tok1', 'request', 'R'));

            $this->expectException(GraphProviderException::class);
            $cache->save('tok1', $graph);
        } finally {
            chmod($readonlyDir, 0o777);
        }
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
