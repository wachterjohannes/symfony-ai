<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Inspector;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphEdge;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\RequestInspector;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RequestInspectorTest extends TestCase
{
    public function testSupportsRequestNodesOnly()
    {
        $inspector = new RequestInspector();

        $request = new GraphNode('request:tok', 'request', 'r');
        $service = new GraphNode('service:s', 'service', 's');

        $this->assertTrue($inspector->supports($request));
        $this->assertFalse($inspector->supports($service));
    }

    public function testTopQueriesSortedByDuration()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('request:tok', 'request', 'GET /api → 200', [
            'method' => 'GET',
            'url' => '/api',
            'status_code' => 200,
        ]));
        $graph->addNode(new GraphNode('query:tok:0', 'query', 'SELECT 1', [
            'sql' => 'SELECT 1',
            'total_time_ms' => 1.0,
        ]));
        $graph->addNode(new GraphNode('query:tok:1', 'query', 'SELECT 2', [
            'sql' => 'SELECT * FROM big_table WHERE id = ?',
            'total_time_ms' => 45.0,
        ]));
        $graph->addNode(new GraphNode('query:tok:2', 'query', 'SELECT 3', [
            'sql' => 'SELECT 3',
            'total_time_ms' => 12.0,
        ]));
        $graph->addEdge(new GraphEdge('request:tok', 'executed_query', 'query:tok:0'));
        $graph->addEdge(new GraphEdge('request:tok', 'executed_query', 'query:tok:1'));
        $graph->addEdge(new GraphEdge('request:tok', 'executed_query', 'query:tok:2'));

        $node = $graph->node('request:tok');
        $this->assertNotNull($node);

        $result = (new RequestInspector())->inspect($node, $graph, []);

        $findingText = implode("\n", $result->findings);
        // The 45ms query must be reported as #1.
        $rank1Pos = strpos($findingText, 'Query #1');
        $bigQueryPos = strpos($findingText, 'big_table');
        $this->assertNotFalse($rank1Pos);
        $this->assertNotFalse($bigQueryPos);
        $this->assertGreaterThan($rank1Pos, $bigQueryPos);
        $this->assertStringContainsString('Query #2', $findingText);
        $this->assertStringContainsString('Query #3', $findingText);
    }

    public function testReportsNoQueriesWhenAbsent()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('request:tok', 'request', 'GET /api', [
            'method' => 'GET',
            'url' => '/api',
        ]));

        $node = $graph->node('request:tok');
        $this->assertNotNull($node);

        $result = (new RequestInspector())->inspect($node, $graph, []);

        $this->assertStringContainsString('No database queries recorded', implode(' ', $result->findings));
    }

    public function testSurfacesExceptionWhenPresent()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('request:tok', 'request', 'GET /api → 500', [
            'method' => 'GET',
            'url' => '/api',
            'status_code' => 500,
        ]));
        $graph->addNode(new GraphNode('exception:RuntimeException', 'exception', 'RuntimeException', [
            'class' => 'RuntimeException',
            'message' => 'Boom',
        ]));
        $graph->addEdge(new GraphEdge('request:tok', 'raised_exception', 'exception:RuntimeException'));

        $node = $graph->node('request:tok');
        $this->assertNotNull($node);

        $result = (new RequestInspector())->inspect($node, $graph, []);

        $findingText = implode(' ', $result->findings);
        $this->assertStringContainsString('Raised exception', $findingText);
        $this->assertStringContainsString('RuntimeException', $findingText);
        $this->assertStringContainsString('Boom', $findingText);
    }
}
