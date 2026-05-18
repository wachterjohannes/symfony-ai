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
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphContext;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraphBuilder;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\RouteGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\RouteInspector;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RouteInspectorTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__).'/Fixtures';
    }

    public function testSupportsRouteNodesOnly()
    {
        $inspector = new RouteInspector();

        $route = new GraphNode('route:r', 'route', 'r');
        $service = new GraphNode('service:s', 'service', 's');

        $this->assertTrue($inspector->supports($route));
        $this->assertFalse($inspector->supports($service));
    }

    public function testInspectExposesPathAndMethodsAndController()
    {
        $inspector = new RouteInspector();
        $graph = $this->buildGraph();

        $node = $graph->node('route:app_checkout');
        $this->assertNotNull($node);

        $result = $inspector->inspect($node, $graph, []);

        $findingText = implode(' ', $result->findings);
        $this->assertStringContainsString('Path: /checkout', $findingText);
        $this->assertStringContainsString('POST', $findingText);
        $this->assertStringContainsString('App\\Controller\\CheckoutController', $findingText);
        // Controller node appears in relatedNodes via the depth-2 walk.
        $controllerInRelated = false;
        foreach ($result->relatedNodes as $node) {
            if (str_starts_with((string) $node['id'], 'controller:App\\Controller\\CheckoutController')) {
                $controllerInRelated = true;
                break;
            }
        }
        $this->assertTrue($controllerInRelated);
    }

    private function buildGraph(): RuntimeGraph
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);
        (new RouteGraphProvider($this->fixturesDir))->populate($builder, new GraphContext());

        return $graph;
    }
}
