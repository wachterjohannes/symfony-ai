<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\GraphProvider;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphContext;
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraphBuilder;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ContainerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\RouteGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RouteGraphProviderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__).'/Fixtures';
    }

    public function testEmitsRouteAndControllerNodes()
    {
        $graph = $this->buildGraph();

        $this->assertNotNull($graph->node('route:app_checkout'));
        $this->assertNotNull($graph->node('controller:App\\Controller\\CheckoutController::__invoke'));
        $this->assertNotNull($graph->node('route:app_home'));
        $this->assertNotNull($graph->node('controller:App\\Controller\\HomeController::index'));
    }

    public function testInvokableControllerFqcnGetsInvokeSuffix()
    {
        $graph = $this->buildGraph();

        // framework_template defines `_controller` as a class FQCN without `::method`.
        $this->assertNotNull($graph->node('controller:Symfony\\Bundle\\FrameworkBundle\\Controller\\TemplateController::__invoke'));
    }

    public function testEmitsHandledByEdge()
    {
        $graph = $this->buildGraph();

        $found = false;
        foreach ($graph->edges() as $edge) {
            if ('route:app_checkout' === $edge->from
                && 'handled_by' === $edge->relation
                && 'controller:App\\Controller\\CheckoutController::__invoke' === $edge->to
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function testRouteNodeCarriesPathAndMethodMetadata()
    {
        $graph = $this->buildGraph();

        $node = $graph->node('route:app_checkout');
        $this->assertNotNull($node);
        $this->assertSame('/checkout', $node->metadata['path']);
        $this->assertSame(['POST'], $node->metadata['methods']);
    }

    public function testControllerLinksToServiceWhenClassMatches()
    {
        // The fixture container declares a service 'app.event_listener' with class
        // App\EventListener\RequestListener. The fixture routes include a route whose
        // controller is that exact FQCN. When both providers populate, the route's
        // controller node should depends_on the matching service node.
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);

        (new ContainerGraphProvider($this->fixturesDir, new ContainerProvider()))
            ->populate($builder, new GraphContext());
        (new RouteGraphProvider($this->fixturesDir))
            ->populate($builder, new GraphContext());

        $found = false;
        foreach ($graph->edges() as $edge) {
            if ('controller:App\\EventListener\\RequestListener::__invoke' === $edge->from
                && 'depends_on' === $edge->relation
                && 'service:app.event_listener' === $edge->to
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected controller→service depends_on edge when FQCN matches.');
    }

    public function testPopulateIsNoOpWhenCacheMissing()
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);
        $provider = new RouteGraphProvider('/non/existent/directory');

        $provider->populate($builder, new GraphContext());

        $this->assertSame([], $graph->nodes());
    }

    public function testNoControllerServiceLinkWhenNoServiceMatches()
    {
        // app_home points at App\Controller\HomeController, which has no matching service
        // in the fixture container. No depends_on edge should be emitted for that controller.
        $graph = $this->buildGraph();

        foreach ($graph->edges() as $edge) {
            $this->assertNotSame(
                'controller:App\\Controller\\HomeController::index',
                $edge->from,
                'HomeController has no matching service; should not emit depends_on edge.',
            );
        }
    }

    private function buildGraph(): RuntimeGraph
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);

        // Pre-populate with service nodes so testControllerLinksToServiceWhenClassMatches
        // and testRouteNodeCarriesPathAndMethodMetadata cover both paths.
        (new ContainerGraphProvider($this->fixturesDir, new ContainerProvider()))
            ->populate($builder, new GraphContext());
        (new RouteGraphProvider($this->fixturesDir))
            ->populate($builder, new GraphContext());

        return $graph;
    }
}
