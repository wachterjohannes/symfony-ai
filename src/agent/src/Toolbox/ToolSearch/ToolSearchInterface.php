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
 * Ranks tool definitions by their relevance for a natural language query.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface ToolSearchInterface
{
    /**
     * @param Tool[] $tools      the tools to search in
     * @param int    $maxResults maximum number of tools to return
     *
     * @return list<Tool> the matching tools, most relevant first
     */
    public function search(string $query, array $tools, int $maxResults): array;
}
