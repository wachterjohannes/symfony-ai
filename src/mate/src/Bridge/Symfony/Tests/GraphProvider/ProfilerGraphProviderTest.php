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
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ProfilerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\CollectorRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\DoctrineCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\ExceptionCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\RequestCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\Formatter\TimeCollectorFormatter;
use Symfony\AI\Mate\Bridge\Symfony\Profiler\Service\ProfilerDataProvider;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ProfilerGraphProviderTest extends TestCase
{
    private ProfilerGraphProvider $provider;

    protected function setUp(): void
    {
        if (!class_exists(FileProfilerStorage::class)) {
            $this->markTestSkipped('symfony/http-kernel profiler classes not available.');
        }

        $registry = new CollectorRegistry([
            new RequestCollectorFormatter(),
            new DoctrineCollectorFormatter(),
            new ExceptionCollectorFormatter(),
            new TimeCollectorFormatter(),
        ]);
        $fixtureDir = \dirname(__DIR__).'/Fixtures/profiler';
        $dataProvider = new ProfilerDataProvider($fixtureDir, $registry);
        $this->provider = new ProfilerGraphProvider($dataProvider);
    }

    public function testEmitsRequestNodeWithMethodPathStatus()
    {
        $graph = $this->populate('abc123');

        $node = $graph->node('request:abc123');
        $this->assertNotNull($node);
        $this->assertSame('request', $node->type);
        $this->assertSame('GET', $node->metadata['method']);
        $this->assertSame('/api/users', $node->metadata['url']);
        $this->assertSame(200, $node->metadata['status_code']);
    }

    public function testNoOpWhenTokenAbsentFromContext()
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);

        $this->provider->populate($builder, new GraphContext());

        $this->assertSame([], $graph->nodes());
    }

    public function testNoOpWhenProfileNotFound()
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);

        $this->provider->populate($builder, new GraphContext(profilerToken: 'does-not-exist'));

        $this->assertSame([], $graph->nodes());
    }

    public function testMatchedRouteEdgeSkippedWhenStaticGraphHasNoMatchingRoute()
    {
        // The fixture profile abc123's request collector has no `_route` default set, so the
        // matched_route edge has nothing to anchor to. The provider must skip silently rather
        // than emitting a dangling edge.
        $graph = $this->populate('abc123');

        foreach ($graph->edges() as $edge) {
            $this->assertNotSame('matched_route', $edge->relation);
        }
    }

    private function populate(string $token): RuntimeGraph
    {
        $graph = new RuntimeGraph();
        $builder = new RuntimeGraphBuilder($graph);
        $this->provider->populate($builder, new GraphContext(profilerToken: $token));

        return $graph;
    }
}
