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

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RuntimeGraphTest extends TestCase
{
    public function testAddNodeIsIdempotent()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:foo', 'service', 'Foo'));
        $graph->addNode(new GraphNode('service:foo', 'service', 'Foo (duplicate)'));

        $this->assertCount(1, $graph->nodes());
        $this->assertSame('Foo', $graph->node('service:foo')?->label);
    }

    public function testAddEdgeIsIdempotent()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A'));
        $graph->addNode(new GraphNode('service:b', 'service', 'B'));
        $graph->addEdge(new GraphEdge('service:a', 'depends_on', 'service:b'));
        $graph->addEdge(new GraphEdge('service:a', 'depends_on', 'service:b'));

        $this->assertCount(1, $graph->edges());
    }

    public function testNodeReturnsNullForUnknownId()
    {
        $graph = new RuntimeGraph();

        $this->assertNull($graph->node('service:missing'));
    }

    public function testNeighborsReturnsStartingNodeOnlyAtDepthZero()
    {
        $graph = $this->buildLinearGraph();

        $view = $graph->neighbors('service:a', 0);

        $this->assertCount(1, $view->nodes);
        $this->assertCount(0, $view->edges);
        $this->assertSame('service:a', $view->nodes[0]->id);
        $this->assertFalse($view->truncated);
    }

    public function testNeighborsAtDepthOne()
    {
        $graph = $this->buildLinearGraph();

        $view = $graph->neighbors('service:a', 1);

        $ids = array_map(static fn (GraphNode $n) => $n->id, $view->nodes);
        sort($ids);

        $this->assertSame(['service:a', 'service:b'], $ids);
        $this->assertCount(1, $view->edges);
    }

    public function testNeighborsAtDepthTwoFollowsTransitiveEdges()
    {
        $graph = $this->buildLinearGraph();

        $view = $graph->neighbors('service:a', 2);

        $ids = array_map(static fn (GraphNode $n) => $n->id, $view->nodes);
        sort($ids);

        $this->assertSame(['service:a', 'service:b', 'service:c'], $ids);
        $this->assertCount(2, $view->edges);
    }

    public function testNeighborsFiltersByRelation()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A'));
        $graph->addNode(new GraphNode('service:b', 'service', 'B'));
        $graph->addNode(new GraphNode('route:r', 'route', 'r'));
        $graph->addEdge(new GraphEdge('service:a', 'depends_on', 'service:b'));
        $graph->addEdge(new GraphEdge('route:r', 'handled_by', 'service:a'));

        $view = $graph->neighbors('service:a', 1, ['depends_on']);

        $ids = array_map(static fn (GraphNode $n) => $n->id, $view->nodes);
        sort($ids);

        $this->assertSame(['service:a', 'service:b'], $ids);
        $this->assertCount(1, $view->edges);
        $this->assertSame('depends_on', $view->edges[0]->relation);
    }

    public function testNeighborsTruncatesWhenMaxNodesReached()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:hub', 'service', 'Hub'));
        for ($i = 0; $i < 5; ++$i) {
            $id = 'service:spoke'.$i;
            $graph->addNode(new GraphNode($id, 'service', 'Spoke'.$i));
            $graph->addEdge(new GraphEdge('service:hub', 'depends_on', $id));
        }

        $view = $graph->neighbors('service:hub', 1, [], 3);

        $this->assertCount(3, $view->nodes);
        $this->assertTrue($view->truncated);
    }

    public function testNeighborsReturnsEmptyViewForUnknownStart()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A'));

        $view = $graph->neighbors('service:missing', 1);

        $this->assertSame([], $view->nodes);
        $this->assertSame([], $view->edges);
    }

    public function testPathDirectEdge()
    {
        $graph = $this->buildLinearGraph();

        $path = $graph->path('service:a', 'service:b');

        $this->assertNotNull($path);
        $this->assertCount(2, $path->nodes);
        $this->assertCount(1, $path->edges);
        $this->assertSame('service:a', $path->nodes[0]->id);
        $this->assertSame('service:b', $path->nodes[1]->id);
    }

    public function testPathTwoHops()
    {
        $graph = $this->buildLinearGraph();

        $path = $graph->path('service:a', 'service:c');

        $this->assertNotNull($path);
        $this->assertCount(3, $path->nodes);
        $this->assertCount(2, $path->edges);
    }

    public function testPathSameNodeReturnsTrivialPath()
    {
        $graph = $this->buildLinearGraph();

        $path = $graph->path('service:a', 'service:a');

        $this->assertNotNull($path);
        $this->assertCount(1, $path->nodes);
        $this->assertCount(0, $path->edges);
    }

    public function testPathReturnsNullWhenUnreachable()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A'));
        $graph->addNode(new GraphNode('service:b', 'service', 'B'));

        $this->assertNull($graph->path('service:a', 'service:b'));
    }

    public function testPathHandlesCyclesWithoutInfiniteLoop()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A'));
        $graph->addNode(new GraphNode('service:b', 'service', 'B'));
        $graph->addEdge(new GraphEdge('service:a', 'depends_on', 'service:b'));
        $graph->addEdge(new GraphEdge('service:b', 'depends_on', 'service:a'));

        $path = $graph->path('service:a', 'service:b');

        $this->assertNotNull($path);
        $this->assertCount(2, $path->nodes);
        $this->assertSame('service:a', $path->nodes[0]->id);
        $this->assertSame('service:b', $path->nodes[1]->id);
    }

    public function testPathRespectsRelationFilter()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A'));
        $graph->addNode(new GraphNode('service:b', 'service', 'B'));
        $graph->addNode(new GraphNode('controller:c', 'controller', 'C'));
        $graph->addEdge(new GraphEdge('service:a', 'depends_on', 'service:b'));
        $graph->addEdge(new GraphEdge('service:b', 'handled_by', 'controller:c'));

        // Unfiltered: full chain reachable.
        $unfiltered = $graph->path('service:a', 'controller:c');
        $this->assertNotNull($unfiltered);
        $this->assertCount(3, $unfiltered->nodes);

        // Filtered to depends_on only: handled_by edge is invisible, target unreachable.
        $this->assertNull($graph->path('service:a', 'controller:c', ['depends_on']));
    }

    public function testPathReturnsNullForDisconnectedComponents()
    {
        $graph = new RuntimeGraph();
        // Cluster 1: a → b → c
        $graph->addNode(new GraphNode('service:a', 'service', 'A'));
        $graph->addNode(new GraphNode('service:b', 'service', 'B'));
        $graph->addNode(new GraphNode('service:c', 'service', 'C'));
        $graph->addEdge(new GraphEdge('service:a', 'depends_on', 'service:b'));
        $graph->addEdge(new GraphEdge('service:b', 'depends_on', 'service:c'));
        // Cluster 2: x → y → z
        $graph->addNode(new GraphNode('service:x', 'service', 'X'));
        $graph->addNode(new GraphNode('service:y', 'service', 'Y'));
        $graph->addNode(new GraphNode('service:z', 'service', 'Z'));
        $graph->addEdge(new GraphEdge('service:x', 'depends_on', 'service:y'));
        $graph->addEdge(new GraphEdge('service:y', 'depends_on', 'service:z'));

        $this->assertNull($graph->path('service:a', 'service:z'));
        $this->assertNull($graph->path('service:c', 'service:x'));
    }

    public function testAdjacencyRebuildsAfterMutation()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A'));
        $graph->addNode(new GraphNode('service:b', 'service', 'B'));
        $graph->addEdge(new GraphEdge('service:a', 'depends_on', 'service:b'));

        $view = $graph->neighbors('service:a', 1);
        $this->assertCount(2, $view->nodes);

        // Add a new node + edge — adjacency must invalidate.
        $graph->addNode(new GraphNode('service:c', 'service', 'C'));
        $graph->addEdge(new GraphEdge('service:a', 'depends_on', 'service:c'));

        $view = $graph->neighbors('service:a', 1);
        $ids = array_map(static fn (GraphNode $n) => $n->id, $view->nodes);
        sort($ids);
        $this->assertSame(['service:a', 'service:b', 'service:c'], $ids);
    }

    private function buildLinearGraph(): RuntimeGraph
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:a', 'service', 'A'));
        $graph->addNode(new GraphNode('service:b', 'service', 'B'));
        $graph->addNode(new GraphNode('service:c', 'service', 'C'));
        $graph->addEdge(new GraphEdge('service:a', 'depends_on', 'service:b'));
        $graph->addEdge(new GraphEdge('service:b', 'depends_on', 'service:c'));

        return $graph;
    }
}
