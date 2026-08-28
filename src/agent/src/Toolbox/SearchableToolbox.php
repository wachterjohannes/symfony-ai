<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Toolbox;

use Symfony\AI\Agent\Toolbox\ToolSearch\Bm25ToolSearch;
use Symfony\AI\Agent\Toolbox\ToolSearch\ToolSearchInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Hides the tools of the decorated toolbox behind a tool the model can use to search for them.
 *
 * Instead of sending all tool definitions with every request, only the always exposed tools and a
 * search tool are advertised. Tools found by a search are added to the exposed tools, so the model
 * can call them in one of the next steps of the same tool calling loop.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SearchableToolbox implements ToolboxInterface, ResetInterface
{
    public const SEARCH_TOOL_NAME = 'tool_search';

    /**
     * Names of the tools found by a search so far.
     *
     * @var list<string>
     */
    private array $foundTools = [];

    /**
     * @param list<string> $alwaysExposedTools names of tools that are exposed without being searched for
     */
    public function __construct(
        private readonly ToolboxInterface $innerToolbox,
        private readonly ToolSearchInterface $toolSearch = new Bm25ToolSearch(),
        private readonly int $maxResults = 5,
        private readonly array $alwaysExposedTools = [],
    ) {
    }

    public function getTools(): array
    {
        $tools = $this->innerToolbox->getTools();
        if ([] === $tools) {
            return [];
        }

        $exposed = [$this->createSearchTool()];
        foreach ($tools as $tool) {
            if (\in_array($tool->getName(), $this->alwaysExposedTools, true) || \in_array($tool->getName(), $this->foundTools, true)) {
                $exposed[] = $tool;
            }
        }

        return $exposed;
    }

    public function execute(ToolCall $toolCall): ToolResult
    {
        if (self::SEARCH_TOOL_NAME !== $toolCall->getName()) {
            return $this->innerToolbox->execute($toolCall);
        }

        $query = $toolCall->getArguments()['query'] ?? null;
        if (!\is_string($query) || '' === trim($query)) {
            return new ToolResult($toolCall, 'The "query" argument is required and must describe the task you need a tool for.');
        }

        $found = $this->toolSearch->search($query, $this->innerToolbox->getTools(), $this->maxResults);
        if ([] === $found) {
            return new ToolResult($toolCall, \sprintf('No tool matching "%s" was found. Try again with different wording or answer without a tool.', $query));
        }

        $lines = [];
        foreach ($found as $tool) {
            if (!\in_array($tool->getName(), $this->foundTools, true)) {
                $this->foundTools[] = $tool->getName();
            }

            $lines[] = \sprintf('- %s: %s', $tool->getName(), $tool->getDescription());
        }

        return new ToolResult($toolCall, \sprintf(
            "The following tools are now available to you and can be called directly:\n%s",
            implode("\n", $lines),
        ));
    }

    public function reset(): void
    {
        $this->foundTools = [];
    }

    private function createSearchTool(): Tool
    {
        return new Tool(
            new ExecutionReference(self::class, 'execute'),
            self::SEARCH_TOOL_NAME,
            'Search for tools to solve the task at hand. Only a subset of the existing tools is listed upfront, all others have to be found with this tool before they can be called.',
            [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Description of what you want to do, for example "send an email" or "convert a currency".',
                    ],
                ],
                'required' => ['query'],
                'additionalProperties' => false,
            ],
        );
    }
}
