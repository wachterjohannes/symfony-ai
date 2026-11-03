<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Tests\Toolbox\ToolSearch;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\ToolSearch\KeywordToolSearch;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

final class KeywordToolSearchTest extends TestCase
{
    public function testExactNameReturnsOnlyThatTool()
    {
        $result = (new KeywordToolSearch())->search(' Mail__Send_Email ', $this->createTools(), 5);

        $this->assertSame(['mail__send_email'], $this->names($result));
    }

    public function testNamePartOutranksDescriptionMatch()
    {
        $result = (new KeywordToolSearch())->search('invoice', $this->createTools(), 5);

        $this->assertSame(['billing__get_invoice', 'mail__send_email'], $this->names($result));
    }

    public function testServerPrefixIsNotANamePart()
    {
        $result = (new KeywordToolSearch())->search('mail', $this->createTools(), 5);

        $this->assertSame(['mail__send_email'], $this->names($result));
    }

    public function testPartialNamePartMatches()
    {
        $result = (new KeywordToolSearch())->search('inv', $this->createTools(), 5);

        $this->assertSame(['billing__get_invoice'], $this->names($result));
    }

    public function testResultsAreLimited()
    {
        $result = (new KeywordToolSearch())->search('invoice', $this->createTools(), 1);

        $this->assertSame(['billing__get_invoice'], $this->names($result));
    }

    public function testEmptyQueryAndNoMatchReturnNothing()
    {
        $search = new KeywordToolSearch();

        $this->assertSame([], $search->search('  ', $this->createTools(), 5));
        $this->assertSame([], $search->search('saxophone', $this->createTools(), 5));
    }

    /**
     * @param list<Tool> $tools
     *
     * @return list<string>
     */
    private function names(array $tools): array
    {
        return array_map(static fn (Tool $tool): string => $tool->getName(), $tools);
    }

    /**
     * @return list<Tool>
     */
    private function createTools(): array
    {
        return [
            new Tool(new ExecutionReference('Mail', 'send'), 'mail__send_email', 'Send an email, optionally with an invoice attached', null),
            new Tool(new ExecutionReference('Billing', 'get'), 'billing__get_invoice', 'Fetch a document by its number', null),
        ];
    }
}
