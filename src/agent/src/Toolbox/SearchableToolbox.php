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

use Symfony\AI\Agent\Toolbox\ToolSearch\KeywordToolSearch;
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
 * The names of all hidden tools are listed in the description of the search tool. A query of the form
 * "select:name_a,name_b" activates tools by name. Exposing found tools changes the tool list between
 * requests, which invalidates the prompt cache of providers that cache the tool definitions.
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
     * @param list<string> $alwaysExposedTools  names of tools that are exposed without being searched for
     * @param int          $activationThreshold a toolbox with this many tools or fewer is exposed directly,
     *                                           without paying for the search tool call
     */
    public function __construct(
        private readonly ToolboxInterface $innerToolbox,
        private readonly ToolSearchInterface $toolSearch = new KeywordToolSearch(),
        private readonly int $maxResults = 5,
        private readonly array $alwaysExposedTools = [],
        private readonly int $activationThreshold = 0,
    ) {
    }

    public function getTools(): array
    {
        $tools = $this->innerToolbox->getTools();
        if ([] === $tools || \count($tools) <= $this->activationThreshold) {
            return $tools;
        }

        $exposed = [$this->createSearchTool($tools)];
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

        if (str_starts_with(strtolower(trim($query)), 'select:')) {
            return $this->select($toolCall, substr(trim($query), 7));
        }

        $found = $this->toolSearch->search($query, $this->innerToolbox->getTools(), $this->maxResults);
        if ([] === $found) {
            return new ToolResult($toolCall, \sprintf('No tool matching "%s" was found. Try again with different wording or answer without a tool.', $query));
        }

        return $this->activate($toolCall, $found);
    }

    public function reset(): void
    {
        $this->foundTools = [];
    }

    private function select(ToolCall $toolCall, string $names): ToolResult
    {
        $byName = [];
        foreach ($this->innerToolbox->getTools() as $tool) {
            $byName[$tool->getName()] = $tool;
        }

        $found = [];
        $missing = [];
        foreach (explode(',', $names) as $name) {
            $name = trim($name);
            if ('' === $name) {
                continue;
            }

            if (isset($byName[$name])) {
                $found[] = $byName[$name];
            } else {
                $missing[] = $name;
            }
        }

        $result = [] === $found ? '' : $this->activate($toolCall, $found)->getResult();
        if ([] !== $missing) {
            $result = trim($result."\n".\sprintf('Unknown tools: %s.', implode(', ', $missing)));
        }

        return new ToolResult($toolCall, '' === $result ? 'No tool names given. Use "select:name_a,name_b".' : $result);
    }

    /**
     * @param list<Tool> $found
     */
    private function activate(ToolCall $toolCall, array $found): ToolResult
    {
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

    /**
     * @param Tool[] $tools
     */
    private function createSearchTool(array $tools): Tool
    {
        $names = [];
        foreach ($tools as $tool) {
            if (!\in_array($tool->getName(), $this->alwaysExposedTools, true)) {
                $names[] = $tool->getName();
            }
        }

        return new Tool(
            new ExecutionReference(self::class, 'execute'),
            self::SEARCH_TOOL_NAME,
            \sprintf(
                'Search for tools to solve the task at hand. Tools listed here are not callable until found with this tool. Search with keywords, or use "select:name_a,name_b" to load tools by name. Searchable tools: %s.',
                implode(', ', $names),
            ),
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
