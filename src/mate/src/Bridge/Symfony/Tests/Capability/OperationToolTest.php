<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Capability;

use HelgeSverre\Toon\DecodeOptions;
use HelgeSverre\Toon\Toon;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Capability\ContextTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphResult;
use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\InspectTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\OperationTool;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ContainerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\RouteGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\InspectorRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\RequestInspector;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\RouteInspector;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\ServiceInspector;
use Symfony\AI\Mate\Bridge\Symfony\Operation\GraphToolBox;
use Symfony\AI\Mate\Bridge\Symfony\Operation\OperationRecipeInterface;
use Symfony\AI\Mate\Bridge\Symfony\Operation\Recipe\ExplainServiceRecipe;
use Symfony\AI\Mate\Bridge\Symfony\Operation\Recipe\InvestigateExceptionRecipe;
use Symfony\AI\Mate\Bridge\Symfony\Operation\Recipe\InvestigatePerformanceRecipe;
use Symfony\AI\Mate\Bridge\Symfony\Operation\RecipeRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class OperationToolTest extends TestCase
{
    private string $fixturesDir;
    private string $tempCacheDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__).'/Fixtures';
        $this->tempCacheDir = sys_get_temp_dir().'/ai_mate_operation_tool_'.uniqid();
        mkdir($this->tempCacheDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tempCacheDir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempCacheDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tempCacheDir);
    }

    public function testListReturnsCatalogue()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->run('list'), DecodeOptions::lenient());

        $this->assertNotEmpty($result['findings']);
        $names = array_map(static fn (array $action) => $action['args']['operation'] ?? null, $result['nextActions']);
        $this->assertContains('investigate_performance', $names);
        $this->assertContains('explain_service', $names);
        $this->assertContains('investigate_exception', $names);
    }

    public function testEmptyOperationFallsBackToCatalogue()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->run(''), DecodeOptions::lenient());

        $this->assertStringContainsString('catalogue', $result['summary']);
    }

    public function testUnknownRecipeReturnsWarning()
    {
        $tool = $this->buildTool();

        $result = Toon::decode($tool->run('does_not_exist'), DecodeOptions::lenient());

        $this->assertSame([], $result['primaryNodes']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertSame('list', $result['nextActions'][0]['args']['operation']);
    }

    public function testDispatchesToRecipe()
    {
        $stubRecipe = new class implements OperationRecipeInterface {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function name(): string
            {
                return 'stub';
            }

            public function description(): string
            {
                return 'stub';
            }

            public function run(array $args, GraphToolBox $tools): GraphResult
            {
                $this->captured = $args;

                return new GraphResult(
                    summary: 'stub ran',
                    primaryNodes: [['id' => 'service:s', 'type' => 'service', 'label' => 's', 'metadata' => []]],
                    findings: ['stub finding'],
                    evidence: [],
                    relatedNodes: [],
                    nextActions: [],
                );
            }
        };
        $registry = new RecipeRegistry([$stubRecipe]);
        $tool = new OperationTool($registry, $this->buildToolBox());

        $result = Toon::decode($tool->run('stub', ['foo' => 'bar']), DecodeOptions::lenient());

        $this->assertSame('stub ran', $result['summary']);
        $this->assertSame(['foo' => 'bar'], $stubRecipe->captured);
    }

    public function testExplainServiceRecipeRoundTripsThroughTool()
    {
        $tool = $this->buildTool();

        $result = Toon::decode(
            $tool->run('explain_service', ['service' => 'service:app.event_listener']),
            DecodeOptions::lenient(),
        );

        $this->assertSame('service:app.event_listener', $result['primaryNodes'][0]['id']);
    }

    private function buildTool(): OperationTool
    {
        $registry = new RecipeRegistry([
            new InvestigatePerformanceRecipe(),
            new ExplainServiceRecipe(),
            new InvestigateExceptionRecipe(),
        ]);

        return new OperationTool($registry, $this->buildToolBox());
    }

    private function buildToolBox(): GraphToolBox
    {
        $staticFactory = new StaticGraphFactory(
            [
                new ContainerGraphProvider($this->fixturesDir, new ContainerProvider()),
                new RouteGraphProvider($this->fixturesDir),
            ],
            new StaticGraphCache($this->tempCacheDir),
            $this->fixturesDir,
        );
        $inspectorRegistry = new InspectorRegistry([
            new ServiceInspector(new ContainerProvider(), $this->fixturesDir),
            new RouteInspector(),
            new RequestInspector(),
        ]);

        return new GraphToolBox(
            new ContextTool($staticFactory, null, null),
            new GraphTool($staticFactory),
            new InspectTool($staticFactory, null, $inspectorRegistry),
        );
    }
}
