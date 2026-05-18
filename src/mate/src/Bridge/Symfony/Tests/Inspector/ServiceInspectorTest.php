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
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraphBuilder;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ContainerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\ServiceInspector;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ServiceInspectorTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__).'/Fixtures';
    }

    public function testSupportsServiceNodesOnly()
    {
        $inspector = new ServiceInspector(new ContainerProvider(), $this->fixturesDir);
        $graph = $this->buildGraph();

        $serviceNode = $graph->node('service:app.event_listener');
        $this->assertNotNull($serviceNode);
        $this->assertTrue($inspector->supports($serviceNode));

        $stub = new \Symfony\AI\Mate\Bridge\Symfony\Graph\GraphNode('route:r', 'route', 'r');
        $this->assertFalse($inspector->supports($stub));
    }

    public function testInspectSurfacesDependenciesAndTags()
    {
        $inspector = new ServiceInspector(new ContainerProvider(), $this->fixturesDir);
        $graph = $this->buildGraph();

        $node = $graph->node('service:app.event_listener');
        $this->assertNotNull($node);

        $result = $inspector->inspect($node, $graph, []);

        // The service node's label is its FQCN (set by ContainerGraphProvider).
        $this->assertStringContainsString('RequestListener', $result->summary);
        $findingText = implode(' ', $result->findings);
        $this->assertStringContainsString('dependenc', $findingText);
        $this->assertStringContainsString('Tags', $findingText);
    }

    public function testInspectSurfacesAliasOf()
    {
        $inspector = new ServiceInspector(new ContainerProvider(), $this->fixturesDir);
        $graph = $this->buildGraph();

        $node = $graph->node('service:my_service');
        $this->assertNotNull($node);

        $result = $inspector->inspect($node, $graph, []);

        $this->assertStringContainsString('Alias of', implode(' ', $result->findings));
    }

    private function buildGraph(): RuntimeGraph
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);
        (new ContainerGraphProvider($this->fixturesDir, new ContainerProvider()))
            ->populate($builder, new GraphContext());

        return $graph;
    }
}
