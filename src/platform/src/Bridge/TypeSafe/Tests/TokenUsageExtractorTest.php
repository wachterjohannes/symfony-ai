<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\TypeSafe\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\TypeSafe\TokenUsageExtractor;
use Symfony\AI\Platform\Result\InMemoryRawResult;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class TokenUsageExtractorTest extends TestCase
{
    public function testItExtractsTokenUsage()
    {
        $tokenUsage = (new TokenUsageExtractor())->extract(new InMemoryRawResult([
            'answers' => [],
            'usage' => ['input_tokens' => 123, 'output_tokens' => 45],
            'model' => 'jev',
        ]));

        $this->assertNotNull($tokenUsage);
        $this->assertSame(123, $tokenUsage->getPromptTokens());
        $this->assertSame(45, $tokenUsage->getCompletionTokens());
        $this->assertSame('jev', $tokenUsage->getModel());
    }

    public function testItReturnsNullWithoutUsage()
    {
        $this->assertNull((new TokenUsageExtractor())->extract(new InMemoryRawResult(['answers' => []])));
    }
}
