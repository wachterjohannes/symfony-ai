<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Store\Tests\Double;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers every request with the next queued result, and records the payloads it received.
 */
final class QueuedPlatformTestHandler implements ModelClientInterface, ResultConverterInterface
{
    /**
     * @var list<mixed>
     */
    public array $payloads = [];

    /**
     * @param list<ResultInterface> $results
     */
    public function __construct(
        private array $results,
    ) {
    }

    /**
     * @param list<ResultInterface> $results
     *
     * @param-out self               $handler
     */
    public static function createPlatform(array $results, ?self &$handler = null): Platform
    {
        $handler = new self($results);

        return new Platform([new Provider('test', [$handler], [$handler], new FallbackModelCatalog())]);
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function request(Model $model, array|string|object $payload, array $options = []): RawHttpResult
    {
        $this->payloads[] = $payload;

        return new RawHttpResult(new MockResponse());
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        return array_shift($this->results) ?? throw new \LogicException('No result queued.');
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return null;
    }
}
