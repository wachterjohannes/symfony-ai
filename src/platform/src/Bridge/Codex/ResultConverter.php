<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Codex;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Metadata\ToolCallTrace;
use Symfony\AI\Platform\Metadata\ToolCallTraceCollection;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;

/**
 * Converts Codex CLI JSONL output into platform result objects.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ResultConverter implements ResultConverterInterface
{
    public function supports(Model $model): bool
    {
        return $model instanceof Codex;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        if ($options['stream'] ?? false) {
            return new StreamResult($this->convertStream($result));
        }

        $data = $result->getData();

        if ([] === $data) {
            throw new RuntimeException('Codex CLI did not return any result.');
        }

        if ('error' === ($data['type'] ?? '')) {
            throw new RuntimeException(\sprintf('Codex CLI error: "%s"', $data['message'] ?? 'Unknown error'));
        }

        $text = $data['item']['text'] ?? null;

        if (null === $text) {
            throw new RuntimeException('Codex CLI result does not contain a text field.');
        }

        $result = new TextResult($text);
        $this->attachToolCallTraceMetadata($result, $data);

        return $result;
    }

    public function getTokenUsageExtractor(): TokenUsageExtractorInterface
    {
        return new TokenUsageExtractor();
    }

    private function convertStream(RawResultInterface $result): \Generator
    {
        foreach ($result->getDataStream() as $data) {
            $type = $data['type'] ?? '';

            if ('item.completed' === $type
                && 'agent_message' === ($data['item']['type'] ?? '')
                && isset($data['item']['text'])
            ) {
                yield new TextDelta($data['item']['text']);
            }
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function attachToolCallTraceMetadata(TextResult $result, array $data): void
    {
        $traces = $data[ToolCallTraceCollection::METADATA_KEY] ?? null;
        if (!\is_array($traces) || [] === $traces) {
            return;
        }

        $result->getMetadata()->add(
            ToolCallTraceCollection::METADATA_KEY,
            new ToolCallTraceCollection(array_map(
                static fn (array $trace): ToolCallTrace => ToolCallTrace::fromArray($trace),
                array_values(array_filter($traces, static fn (mixed $trace): bool => \is_array($trace) && isset($trace['name']) && \is_string($trace['name']))),
            )),
        );
    }
}
