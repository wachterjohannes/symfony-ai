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
use Symfony\AI\Mate\Bridge\Symfony\Graph\ControllerServiceLinker;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ControllerServiceLinkerTest extends TestCase
{
    public function testLinkIsNoOpOnEmptyGraph()
    {
        $graph = new RuntimeGraph();

        (new ControllerServiceLinker())->link($graph);

        $this->assertSame([], $graph->edges());
    }

    public function testEmitsDependsOnEdgeWhenControllerClassMatchesService()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:app.controller', 'service', 'AppController', [
            'class' => 'App\\Controller\\AppController',
        ]));
        $graph->addNode(new GraphNode('controller:App\\Controller\\AppController::index', 'controller', 'AppController::index', [
            'class' => 'App\\Controller\\AppController',
        ]));

        (new ControllerServiceLinker())->link($graph);

        $edges = $graph->edges();
        $this->assertCount(1, $edges);
        $this->assertSame('controller:App\\Controller\\AppController::index', $edges[0]->from);
        $this->assertSame('depends_on', $edges[0]->relation);
        $this->assertSame('service:app.controller', $edges[0]->to);
    }

    public function testEmitsOneEdgePerMatchingService()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:primary', 'service', 'Primary', [
            'class' => 'App\\Controller\\AppController',
        ]));
        $graph->addNode(new GraphNode('service:decorator', 'service', 'Decorator', [
            'class' => 'App\\Controller\\AppController',
        ]));
        $graph->addNode(new GraphNode('controller:App\\Controller\\AppController::index', 'controller', 'AppController::index', [
            'class' => 'App\\Controller\\AppController',
        ]));

        (new ControllerServiceLinker())->link($graph);

        $depsOn = [];
        foreach ($graph->edges() as $edge) {
            if ('depends_on' === $edge->relation) {
                $depsOn[] = $edge->to;
            }
        }
        sort($depsOn);

        $this->assertSame(['service:decorator', 'service:primary'], $depsOn);
    }

    public function testEmitsNoEdgeWhenControllerHasNoMatchingService()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:other', 'service', 'Other', [
            'class' => 'App\\Other\\Service',
        ]));
        $graph->addNode(new GraphNode('controller:App\\Controller\\AppController::index', 'controller', 'AppController::index', [
            'class' => 'App\\Controller\\AppController',
        ]));

        (new ControllerServiceLinker())->link($graph);

        $this->assertSame([], $graph->edges());
    }

    public function testIgnoresControllerNodeWithoutClassMetadata()
    {
        $graph = new RuntimeGraph();
        $graph->addNode(new GraphNode('service:s', 'service', 'S', ['class' => 'App\\X']));
        $graph->addNode(new GraphNode('controller:weird', 'controller', 'Weird'));

        (new ControllerServiceLinker())->link($graph);

        $this->assertSame([], $graph->edges());
    }
}
