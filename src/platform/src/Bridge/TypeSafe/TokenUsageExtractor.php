<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\TypeSafe;

use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class TokenUsageExtractor implements TokenUsageExtractorInterface
{
    public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
    {
        $content = $rawResult->getData();

        $usage = $content['usage'] ?? null;
        if (!\is_array($usage)) {
            return null;
        }

        return new TokenUsage(
            promptTokens: $usage['input_tokens'] ?? null,
            completionTokens: $usage['output_tokens'] ?? null,
            model: $content['model'] ?? null,
        );
    }
}
