<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\ClaudeCode;

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

/**
 * Wraps a Symfony Process running the Claude Code CLI as a RawResultInterface.
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class RawProcessResult implements RawResultInterface
{
    private const TOOL_CALL_TRACES = 'tool_call_traces';

    public function __construct(
        private readonly Process $process,
    ) {
    }

    /**
     * Waits for the process to finish, parses all output lines, and returns the final
     * result message (type=result).
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->process->wait();

        if (!$this->process->isSuccessful()) {
            throw new RuntimeException(\sprintf('Claude Code CLI process failed: "%s"', $this->process->getErrorOutput()));
        }

        $output = $this->process->getOutput();
        $result = [];
        $events = [];

        foreach (explode(\PHP_EOL, $output) as $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (null === $decoded) {
                continue;
            }

            $events[] = $decoded;

            if (isset($decoded['type']) && 'result' === $decoded['type']) {
                $result = $decoded;
            }
        }

        $toolCallTraces = $this->extractToolCallTraces($events);
        if ([] !== $toolCallTraces && [] !== $result) {
            $result[self::TOOL_CALL_TRACES] = $toolCallTraces;
        }

        return $result;
    }

    /**
     * Polls the process for incremental output, yielding each complete JSON line.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function getDataStream(): \Generator
    {
        $buffer = '';

        while ($this->process->isRunning()) {
            $incrementalOutput = $this->process->getIncrementalOutput();

            if ('' === $incrementalOutput) {
                usleep(10000); // 10ms polling interval
                continue;
            }

            $buffer .= $incrementalOutput;
            $lines = explode(\PHP_EOL, $buffer);

            // Keep the last (potentially incomplete) line in the buffer
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);

                if ('' === $line) {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (null !== $decoded) {
                    yield $decoded;
                }
            }
        }

        // Process remaining output after process finishes
        $buffer .= $this->process->getIncrementalOutput();

        foreach (explode(\PHP_EOL, $buffer) as $line) {
            $line = trim($line);

            if ('' === $line) {
                continue;
            }

            $decoded = json_decode($line, true);

            if (null !== $decoded) {
                yield $decoded;
            }
        }

        if (!$this->process->isSuccessful()) {
            throw new RuntimeException(\sprintf('Claude Code CLI process failed: "%s"', $this->process->getErrorOutput()));
        }
    }

    public function getObject(): Process
    {
        return $this->process;
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array{
     *     id: ?string,
     *     name: string,
     *     arguments: array<string, mixed>,
     *     started_at_ms: ?float,
     *     duration_ms: ?float,
     *     errored: bool
     * }>
     */
    private function extractToolCallTraces(array $events): array
    {
        $traces = [];
        $started = [];
        $seenIds = [];

        foreach ($events as $index => $event) {
            $type = $event['type'] ?? null;
            if ('assistant' === $type) {
                $message = $event['message'] ?? null;
                if (\is_array($message)) {
                    $this->collectCompletedToolUsesFromMessage($traces, $seenIds, $message);
                }

                continue;
            }

            if ('stream_event' !== $type) {
                continue;
            }

            $streamEvent = $event['event'] ?? null;
            if (!\is_array($streamEvent)) {
                continue;
            }

            $streamType = $streamEvent['type'] ?? null;
            $key = (string) ($streamEvent['index'] ?? $index);

            if ('content_block_start' === $streamType) {
                $contentBlock = $streamEvent['content_block'] ?? null;
                if (\is_array($contentBlock) && 'tool_use' === ($contentBlock['type'] ?? null)) {
                    $started[$key] = [
                        'id' => isset($contentBlock['id']) && \is_string($contentBlock['id']) ? $contentBlock['id'] : null,
                        'name' => (string) ($contentBlock['name'] ?? ''),
                        'arguments' => \is_array($contentBlock['input'] ?? null) ? $contentBlock['input'] : [],
                        'partial_input' => '',
                        'started_at_ms' => $this->extractMilliseconds($event),
                        'duration_ms' => null,
                        'errored' => false,
                    ];
                }

                continue;
            }

            if ('content_block_delta' === $streamType && isset($started[$key])) {
                $delta = $streamEvent['delta'] ?? null;
                if (\is_array($delta) && 'input_json_delta' === ($delta['type'] ?? null) && \is_string($delta['partial_json'] ?? null)) {
                    $started[$key]['partial_input'] .= $delta['partial_json'];
                }

                continue;
            }

            if ('content_block_stop' === $streamType && isset($started[$key])) {
                $trace = $started[$key];
                unset($started[$key]);

                if ([] === $trace['arguments'] && '' !== $trace['partial_input']) {
                    try {
                        $decoded = json_decode($trace['partial_input'], true, 512, \JSON_THROW_ON_ERROR);
                    } catch (\JsonException) {
                        $decoded = null;
                    }

                    if (\is_array($decoded)) {
                        $trace['arguments'] = $decoded;
                    }
                }

                unset($trace['partial_input']);

                if ('' !== $trace['name']) {
                    $traces[] = $trace;
                    if (null !== $trace['id']) {
                        $seenIds[$trace['id']] = true;
                    }
                }
            }
        }

        return $traces;
    }

    /**
     * @param list<array{
     *     id: ?string,
     *     name: string,
     *     arguments: array<string, mixed>,
     *     started_at_ms: ?float,
     *     duration_ms: ?float,
     *     errored: bool
     * }>                    $traces
     * @param array<string, true>  $seenIds
     * @param array<string, mixed> $message
     */
    private function collectCompletedToolUsesFromMessage(array &$traces, array &$seenIds, array $message): void
    {
        $content = $message['content'] ?? null;
        if (!\is_array($content)) {
            return;
        }

        foreach ($content as $block) {
            if (!\is_array($block) || 'tool_use' !== ($block['type'] ?? null) || !\is_string($block['name'] ?? null) || '' === $block['name']) {
                continue;
            }

            $id = isset($block['id']) && \is_string($block['id']) ? $block['id'] : null;
            if (null !== $id && isset($seenIds[$id])) {
                continue;
            }

            $traces[] = [
                'id' => $id,
                'name' => $block['name'],
                'arguments' => \is_array($block['input'] ?? null) ? $block['input'] : [],
                'started_at_ms' => null,
                'duration_ms' => null,
                'errored' => false,
            ];

            if (null !== $id) {
                $seenIds[$id] = true;
            }
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function extractMilliseconds(array $event): ?float
    {
        foreach (['timestamp', 'created_at', 'started_at'] as $key) {
            $value = $event[$key] ?? null;
            if (\is_int($value) || \is_float($value)) {
                return (float) $value;
            }

            if (\is_string($value) && '' !== $value) {
                $timestamp = strtotime($value);
                if (false !== $timestamp) {
                    return $timestamp * 1000.0;
                }
            }
        }

        return null;
    }
}
