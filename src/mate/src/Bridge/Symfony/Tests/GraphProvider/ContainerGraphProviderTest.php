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
use Symfony\AI\Mate\Bridge\Symfony\Graph\GraphEdge;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraph;
use Symfony\AI\Mate\Bridge\Symfony\Graph\RuntimeGraphBuilder;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ContainerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ContainerGraphProviderTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__).'/Fixtures';
    }

    public function testPopulatesServiceNodes()
    {
        $graph = $this->buildGraph();

        $this->assertNotNull($graph->node('service:cache.app'));
        $this->assertNotNull($graph->node('service:logger'));
        $this->assertNotNull($graph->node('service:event_dispatcher'));
        $this->assertNotNull($graph->node('service:app.event_listener'));
    }

    public function testServiceNodeCarriesClassAndTagMetadata()
    {
        $graph = $this->buildGraph();

        $node = $graph->node('service:cache.app');
        $this->assertNotNull($node);
        $this->assertSame('Symfony\\Component\\Cache\\Adapter\\FilesystemAdapter', $node->metadata['class']);
        $this->assertSame(['cache.pool'], $node->metadata['tags']);
    }

    public function testEmitsDependsOnEdgesFromConstructorArguments()
    {
        $graph = $this->buildGraph();

        $hasEventDispatcherEdge = false;
        $hasLoggerEdge = false;
        foreach ($graph->edges() as $edge) {
            if ('service:app.event_listener' === $edge->from
                && 'depends_on' === $edge->relation
                && 'service:event_dispatcher' === $edge->to
            ) {
                $hasEventDispatcherEdge = true;
            }
            if ('service:app.event_listener' === $edge->from
                && 'depends_on' === $edge->relation
                && 'service:logger' === $edge->to
            ) {
                $hasLoggerEdge = true;
            }
        }

        $this->assertTrue($hasEventDispatcherEdge, 'Expected depends_on edge to event_dispatcher.');
        $this->assertTrue($hasLoggerEdge, 'Expected depends_on edge to logger.');
    }

    public function testPopulateIsNoOpWhenContainerXmlMissing()
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);
        $provider = new ContainerGraphProvider('/non/existent/directory', new ContainerProvider());

        $provider->populate($builder, new GraphContext());

        $this->assertSame([], $graph->nodes());
        $this->assertSame([], $graph->edges());
    }

    public function testProducesAtLeastOneDependsOnEdge()
    {
        $graph = $this->buildGraph();

        $hasDependsOn = false;
        foreach ($graph->edges() as $edge) {
            if ('depends_on' === $edge->relation) {
                $hasDependsOn = true;
                break;
            }
        }

        $this->assertTrue($hasDependsOn);
    }

    private function buildGraph(): RuntimeGraph
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);
        $provider = new ContainerGraphProvider($this->fixturesDir, new ContainerProvider());
        $provider->populate($builder, new GraphContext());

        return $graph;
    }
}
