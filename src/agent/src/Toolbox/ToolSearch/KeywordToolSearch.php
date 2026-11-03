<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Toolbox\ToolSearch;

use Symfony\AI\Platform\Tool\Tool;

/**
 * Ranks tools by matching the query terms against name parts, names and descriptions.
 *
 * A query that equals a tool name returns only that tool. Otherwise every term scores once per tool:
 * 10 for an exact name part, 5 for a part containing it, 4 for a whole word in the description and
 * 3 for the full name containing it.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class KeywordToolSearch implements ToolSearchInterface
{
    public function search(string $query, array $tools, int $maxResults): array
    {
        $query = strtolower(trim($query));
        $tools = array_values($tools);

        if ('' === $query || $maxResults < 1) {
            return [];
        }

        foreach ($tools as $tool) {
            if (strtolower($tool->getName()) === $query) {
                return [$tool];
            }
        }

        $terms = preg_split('/\s+/', $query, -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $terms) {
            return [];
        }

        $scores = [];
        foreach ($tools as $index => $tool) {
            $score = 0;
            foreach ($terms as $term) {
                $score += $this->scoreTerm($term, $tool);
            }

            if ($score > 0) {
                $scores[$index] = $score;
            }
        }

        arsort($scores);

        return array_map(static fn (int $index): Tool => $tools[$index], \array_slice(array_keys($scores), 0, $maxResults));
    }

    private function scoreTerm(string $term, Tool $tool): int
    {
        $name = strtolower($tool->getName());

        $parts = array_filter(explode('_', $this->stripServerPrefix($name)), static fn (string $part): bool => '' !== $part);
        if (\in_array($term, $parts, true)) {
            return 10;
        }

        foreach ($parts as $part) {
            if (str_contains($part, $term)) {
                return 5;
            }
        }

        if (1 === preg_match('/\b'.preg_quote($term, '/').'\b/i', $tool->getDescription())) {
            return 4;
        }

        if (str_contains($name, $term)) {
            return 3;
        }

        return 0;
    }

    private function stripServerPrefix(string $name): string
    {
        $position = strpos($name, '__');

        return false === $position ? $name : substr($name, $position + 2);
    }
}
