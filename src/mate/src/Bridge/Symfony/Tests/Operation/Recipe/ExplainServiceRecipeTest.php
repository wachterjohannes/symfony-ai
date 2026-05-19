<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Tests\Operation\Recipe;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Bridge\Symfony\Capability\ContextTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\GraphTool;
use Symfony\AI\Mate\Bridge\Symfony\Capability\InspectTool;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphCache;
use Symfony\AI\Mate\Bridge\Symfony\Graph\StaticGraphFactory;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\ContainerGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\GraphProvider\RouteGraphProvider;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\InspectorRegistry;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\RequestInspector;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\RouteInspector;
use Symfony\AI\Mate\Bridge\Symfony\Inspector\ServiceInspector;
use Symfony\AI\Mate\Bridge\Symfony\Operation\GraphToolBox;
use Symfony\AI\Mate\Bridge\Symfony\Operation\Recipe\ExplainServiceRecipe;
use Symfony\AI\Mate\Bridge\Symfony\Service\ContainerProvider;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ExplainServiceRecipeTest extends TestCase
{
    private string $fixturesDir;
    private string $tempCacheDir;

    protected function setUp(): void
    {
        $this->fixturesDir = \dirname(__DIR__, 2).'/Fixtures';
        $this->tempCacheDir = sys_get_temp_dir().'/ai_mate_recipe_explain_'.uniqid();
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

    public function testHasStableName()
    {
        $this->assertSame('explain_service', (new ExplainServiceRecipe())->name());
    }

    public function testRequiresServiceArgument()
    {
        $result = (new ExplainServiceRecipe())->run([], $this->buildToolBox());

        $this->assertSame([], $result->primaryNodes);
        $this->assertNotEmpty($result->warnings);
    }

    public function testProducesEnvelopeForKnownService()
    {
        $recipe = new ExplainServiceRecipe();

        $result = $recipe->run(['service' => 'service:app.event_listener'], $this->buildToolBox());

        $this->assertCount(1, $result->primaryNodes);
        $this->assertSame('service:app.event_listener', $result->primaryNodes[0]['id']);
        $this->assertNotEmpty($result->findings);
        // Graph hop adds extra edges into evidence — at minimum the inspect-level edges.
        $this->assertNotEmpty($result->evidence);
        // Bare class FQCN (no `service:` prefix) is auto-normalised.
        $alt = $recipe->run(['service' => 'app.event_listener'], $this->buildToolBox());
        $this->assertSame($result->primaryNodes, $alt->primaryNodes);
    }

    public function testUnknownServiceReturnsWarning()
    {
        $result = (new ExplainServiceRecipe())->run(['service' => 'service:does.not.exist'], $this->buildToolBox());

        $this->assertSame([], $result->primaryNodes);
        $this->assertNotEmpty($result->warnings);
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

        $registry = new InspectorRegistry([
            new ServiceInspector(new ContainerProvider(), $this->fixturesDir),
            new RouteInspector(),
            new RequestInspector(),
        ]);

        $context = new ContextTool($staticFactory, null, null);
        $graph = new GraphTool($staticFactory);
        $inspect = new InspectTool($staticFactory, null, $registry);

        return new GraphToolBox($context, $graph, $inspect);
    }
}
