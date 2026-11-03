<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Tests\Toolbox;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\SearchableToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

final class SearchableToolboxTest extends TestCase
{
    public function testExposesOnlySearchToolByDefault()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox());

        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME], $this->getToolNames($toolbox));
    }

    public function testExposesToolsThatAreNotDeferred()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox($this->toolsWithClock()));

        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME, 'clock'], $this->getToolNames($toolbox));
    }

    public function testExposesAllToolsWithoutDeferredTools()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox([new Tool(new ExecutionReference('Clock'), 'clock', 'Get the time')]));

        $this->assertSame(['clock'], $this->getToolNames($toolbox));
    }

    public function testExposedToolsAreNotDeferred()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox($this->toolsWithClock()), maxResults: 1);
        $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME, ['query' => 'send an email']));

        foreach ($toolbox->getTools() as $tool) {
            $this->assertFalse($tool->isDeferred(), $tool->getName());
        }
    }

    public function testExposesNothingForEmptyInnerToolbox()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox([]));

        $this->assertSame([], $toolbox->getTools());
    }

    public function testExposesToolsDirectlyBelowActivationThreshold()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox(), activationThreshold: 2);

        $this->assertSame(['send_email', 'get_invoice'], $this->getToolNames($toolbox));
        foreach ($toolbox->getTools() as $tool) {
            $this->assertFalse($tool->isDeferred());
        }
    }

    public function testExposesSearchToolAboveActivationThreshold()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox(), activationThreshold: 1);

        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME], $this->getToolNames($toolbox));
    }

    public function testSearchToolDescriptionListsSearchableToolNames()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox($this->toolsWithClock()));

        $description = $toolbox->getTools()[0]->getDescription();

        $this->assertStringContainsString('send_email', $description);
        $this->assertStringContainsString('get_invoice', $description);
        $this->assertStringNotContainsString('clock', $description);
    }

    public function testSearchToolDescriptionStaysStableAfterSearch()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox());
        $before = $toolbox->getTools()[0]->getDescription();

        $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME, ['query' => 'send an email']));

        $this->assertSame($before, $toolbox->getTools()[0]->getDescription());
    }

    public function testSelectExposesToolsByName()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox());

        $result = $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME, ['query' => 'select:get_invoice, send_email']));

        $this->assertSame(
            "The following tools are now available to you and can be called directly:\n- get_invoice: Fetch an invoice by its number\n- send_email: Send an email to a recipient",
            $result->getResult(),
        );
        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME, 'send_email', 'get_invoice'], $this->getToolNames($toolbox));
    }

    public function testSelectReportsUnknownTools()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox());

        $result = $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME, ['query' => 'select:send_email,nope']));

        $this->assertStringContainsString('- send_email:', $result->getResult());
        $this->assertStringContainsString('Unknown tools: nope.', $result->getResult());
        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME, 'send_email'], $this->getToolNames($toolbox));
    }

    public function testSelectWithoutNames()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox());

        $result = $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME, ['query' => 'select:']));

        $this->assertSame('No tool names given. Use "select:name_a,name_b".', $result->getResult());
    }

    public function testSearchToolHasQueryParameter()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox());

        $parameters = $toolbox->getTools()[0]->getParameters();

        $this->assertNotNull($parameters);
        $this->assertSame(['query'], array_keys($parameters['properties']));
    }

    public function testFoundToolsGetExposed()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox(), maxResults: 1);

        $result = $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME, ['query' => 'send an email']));

        $this->assertSame(
            "The following tools are now available to you and can be called directly:\n- send_email: Send an email to a recipient",
            $result->getResult(),
        );
        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME, 'send_email'], $this->getToolNames($toolbox));
    }

    public function testFoundToolsAccumulate()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox(), maxResults: 1);

        $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME, ['query' => 'send an email']));
        $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME, ['query' => 'fetch an invoice']));

        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME, 'send_email', 'get_invoice'], $this->getToolNames($toolbox));
    }

    public function testSearchWithoutMatch()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox());

        $result = $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME, ['query' => 'jazz saxophone melody']));

        $this->assertSame(
            'No tool matching "jazz saxophone melody" was found. Try again with different wording or answer without a tool.',
            $result->getResult(),
        );
        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME], $this->getToolNames($toolbox));
    }

    public function testSearchWithoutQueryArgument()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox());

        $result = $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME));

        $this->assertSame('The "query" argument is required and must describe the task you need a tool for.', $result->getResult());
    }

    public function testExecutionIsDelegatedToInnerToolbox()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox());

        $result = $toolbox->execute($toolCall = new ToolCall('id', 'send_email'));

        $this->assertSame('executed send_email', $result->getResult());
        $this->assertSame($toolCall, $result->getToolCall());
    }

    public function testResetForgetsFoundTools()
    {
        $toolbox = new SearchableToolbox($this->createInnerToolbox(), maxResults: 1);
        $toolbox->execute(new ToolCall('id', SearchableToolbox::SEARCH_TOOL_NAME, ['query' => 'send an email']));

        $toolbox->reset();

        $this->assertSame([SearchableToolbox::SEARCH_TOOL_NAME], $this->getToolNames($toolbox));
    }

    /**
     * @return list<string>
     */
    private function getToolNames(SearchableToolbox $toolbox): array
    {
        return array_map(static fn (Tool $tool): string => $tool->getName(), $toolbox->getTools());
    }

    /**
     * @return list<Tool>
     */
    private function toolsWithClock(): array
    {
        return [
            new Tool(new ExecutionReference('Clock'), 'clock', 'Get the current time'),
            new Tool(new ExecutionReference('EmailTool', 'send'), 'send_email', 'Send an email to a recipient', null, deferred: true),
            new Tool(new ExecutionReference('InvoiceTool'), 'get_invoice', 'Fetch an invoice by its number', null, deferred: true),
        ];
    }

    /**
     * @param list<Tool>|null $tools
     */
    private function createInnerToolbox(?array $tools = null): ToolboxInterface
    {
        $tools ??= [
            new Tool(new ExecutionReference('EmailTool', 'send'), 'send_email', 'Send an email to a recipient', null, deferred: true),
            new Tool(new ExecutionReference('InvoiceTool'), 'get_invoice', 'Fetch an invoice by its number', null, deferred: true),
        ];

        return new class($tools) implements ToolboxInterface {
            /**
             * @param list<Tool> $tools
             */
            public function __construct(private readonly array $tools)
            {
            }

            public function getTools(): array
            {
                return $this->tools;
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                return new ToolResult($toolCall, 'executed '.$toolCall->getName());
            }
        };
    }
}
