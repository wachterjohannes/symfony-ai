<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Tests\InputProcessor;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Input;
use Symfony\AI\Agent\InputProcessor\ToolSearchInputProcessor;
use Symfony\AI\Agent\Toolbox\SearchableToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

final class ToolSearchInputProcessorTest extends TestCase
{
    public function testModelWithToolSearchGetsTheToolsUntouched()
    {
        $toolbox = $this->createToolbox();

        $this->process($toolbox, $this->createPlatform(new Model('model', [Capability::TOOL_SEARCH])));

        $this->assertSame(['get_invoice'], $this->toolNames($toolbox));
        $this->assertTrue($toolbox->getTools()[0]->isDeferred());
    }

    public function testModelWithoutToolSearchGetsTheFallback()
    {
        $toolbox = $this->createToolbox();

        $this->process($toolbox, $this->createPlatform(new Model('model', [Capability::TOOL_CALLING])));

        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME], $this->toolNames($toolbox));
    }

    public function testUnknownModelGetsTheFallback()
    {
        $toolbox = $this->createToolbox();
        $toolbox->useProviderSearch(true);

        $this->process($toolbox, $this->createPlatform(new ModelNotFoundException('Unknown model.')));

        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME], $this->toolNames($toolbox));
    }

    /**
     * @return list<string>
     */
    private function toolNames(SearchableToolbox $toolbox): array
    {
        return array_map(static fn (Tool $tool): string => $tool->getName(), $toolbox->getTools());
    }

    private function process(SearchableToolbox $toolbox, PlatformInterface $platform): void
    {
        (new ToolSearchInputProcessor($platform, $toolbox))->processInput(new Input('model', new MessageBag()));
    }

    private function createPlatform(Model|\Throwable $model): PlatformInterface
    {
        $catalog = $this->createStub(ModelCatalogInterface::class);
        if ($model instanceof \Throwable) {
            $catalog->method('getModel')->willThrowException($model);
        } else {
            $catalog->method('getModel')->willReturn($model);
        }

        $platform = $this->createStub(PlatformInterface::class);
        $platform->method('getModelCatalog')->willReturn($catalog);

        return $platform;
    }

    private function createToolbox(): SearchableToolbox
    {
        return new SearchableToolbox(new class implements ToolboxInterface {
            public function getTools(): array
            {
                return [new Tool(new ExecutionReference('InvoiceTool'), 'get_invoice', 'Fetch an invoice', null, deferred: true)];
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                return new ToolResult($toolCall, 'executed');
            }
        });
    }
}
