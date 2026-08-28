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
use Symfony\AI\Agent\Toolbox\ToolSearch\Bm25ToolSearch;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;

final class Bm25ToolSearchTest extends TestCase
{
    public function testRanksMatchingToolFirst()
    {
        $found = (new Bm25ToolSearch())->search('I want to send an email', $this->createTools(), 1);

        $this->assertCount(1, $found);
        $this->assertSame('send_email', $found[0]->getName());
    }

    public function testMatchesOnToolName()
    {
        $found = (new Bm25ToolSearch())->search('convert currency', $this->createTools(), 1);

        $this->assertSame('currency_converter', $found[0]->getName());
    }

    public function testMatchesOnParameterDescription()
    {
        $found = (new Bm25ToolSearch())->search('IBAN', $this->createTools(), 3);

        $this->assertCount(1, $found);
        $this->assertSame('transfer_money', $found[0]->getName());
    }

    public function testNormalizesPlurals()
    {
        $found = (new Bm25ToolSearch())->search('list all invoices', $this->createTools(), 1);

        $this->assertSame('get_invoice', $found[0]->getName());
    }

    public function testRespectsMaxResults()
    {
        $found = (new Bm25ToolSearch())->search('email invoice money', $this->createTools(), 2);

        $this->assertCount(2, $found);
    }

    public function testReturnsEmptyArrayWithoutMatch()
    {
        $this->assertSame([], (new Bm25ToolSearch())->search('jazz saxophone melody', $this->createTools(), 5));
    }

    public function testReturnsEmptyArrayForBlankQuery()
    {
        $this->assertSame([], (new Bm25ToolSearch())->search('   ', $this->createTools(), 5));
    }

    public function testReturnsEmptyArrayWithoutTools()
    {
        $this->assertSame([], (new Bm25ToolSearch())->search('send an email', [], 5));
    }

    public function testReturnsEmptyArrayForNonPositiveMaxResults()
    {
        $this->assertSame([], (new Bm25ToolSearch())->search('send an email', $this->createTools(), 0));
    }

    public function testPrefersMoreSpecificToolOverGenericOne()
    {
        $tools = [
            new Tool(new ExecutionReference('SearchTool'), 'search', 'Search for anything, anywhere, at any time, in any of the connected systems', null),
            new Tool(new ExecutionReference('WeatherTool'), 'weather_forecast', 'Get the weather forecast for a city', null),
        ];

        $found = (new Bm25ToolSearch())->search('what is the weather in Berlin', $tools, 1);

        $this->assertSame('weather_forecast', $found[0]->getName());
    }

    /**
     * @return list<Tool>
     */
    private function createTools(): array
    {
        return [
            new Tool(new ExecutionReference('EmailTool', 'send'), 'send_email', 'Send an email to a recipient', [
                'type' => 'object',
                'properties' => [
                    'recipient' => ['type' => 'string', 'description' => 'The address of the receiver'],
                ],
                'required' => ['recipient'],
                'additionalProperties' => false,
            ]),
            new Tool(new ExecutionReference('InvoiceTool'), 'get_invoice', 'Fetch an invoice by its number', null),
            new Tool(new ExecutionReference('CurrencyTool'), 'currency_converter', 'Convert an amount from one currency into another', null),
            new Tool(new ExecutionReference('BankTool'), 'transfer_money', 'Transfer money to a bank account', [
                'type' => 'object',
                'properties' => [
                    'account' => ['type' => 'string', 'description' => 'The IBAN of the target account'],
                ],
                'required' => ['account'],
                'additionalProperties' => false,
            ]),
        ];
    }
}
