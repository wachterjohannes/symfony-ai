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
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraphBuilder;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\Exception\GraphProviderException;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\RouteGraphProvider;

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

    public function testReconstructsPathWithVariables()
    {
        $graph = $this->buildGraph();

        $node = $graph->node('route:app_user_post');
        $this->assertNotNull($node);
        $this->assertSame('/users/{id}/posts/{post}', $node->metadata['path']);
    }

    public function testEmitsNoDependsOnEdgesForControllers()
    {
        // RouteGraphProvider no longer emits controller→service edges — that step now
        // lives in ControllerServiceLinker and runs after providers in StaticGraphFactory.
        $graph = $this->buildGraph();

        foreach ($graph->edges() as $edge) {
            $this->assertNotSame(
                'depends_on',
                $edge->relation,
                'RouteGraphProvider should not emit depends_on edges directly.',
            );
        }
    }

    public function testPopulateIsNoOpWhenCacheMissing()
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);
        $provider = new RouteGraphProvider('/non/existent/directory');

        $provider->populate($builder, new GraphContext());

        $this->assertSame([], $graph->nodes());
    }

    public function testCorruptRouterCacheThrowsGraphProviderException()
    {
        $tmpDir = sys_get_temp_dir().'/ai_mate_route_provider_corrupt_'.uniqid();
        mkdir($tmpDir, 0o777, true);
        file_put_contents(
            $tmpDir.'/url_generating_routes.php',
            "<?php\n\nthrow new \\Error('boom');\n",
        );

        try {
            $graph = new RuntimeGraph();
            $builder = new RuntimeGraphBuilder($graph);
            $provider = new RouteGraphProvider($tmpDir);

            $this->expectException(GraphProviderException::class);
            $provider->populate($builder, new GraphContext());
        } finally {
            @unlink($tmpDir.'/url_generating_routes.php');
            @rmdir($tmpDir);
        }
    }

    public function testNonArrayRouterCacheThrowsGraphProviderException()
    {
        $tmpDir = sys_get_temp_dir().'/ai_mate_route_provider_nonarray_'.uniqid();
        mkdir($tmpDir, 0o777, true);
        file_put_contents(
            $tmpDir.'/url_generating_routes.php',
            "<?php\n\nreturn 'not an array';\n",
        );

        try {
            $graph = new RuntimeGraph();
            $builder = new RuntimeGraphBuilder($graph);
            $provider = new RouteGraphProvider($tmpDir);

            $this->expectException(GraphProviderException::class);
            $provider->populate($builder, new GraphContext());
        } finally {
            @unlink($tmpDir.'/url_generating_routes.php');
            @rmdir($tmpDir);
        }
    }

    private function buildGraph(): RuntimeGraph
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);

        (new RouteGraphProvider($this->fixturesDir))
            ->populate($builder, new GraphContext());

        return $graph;
    }
}
