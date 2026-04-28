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
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\Component\Process\Process;

/**
 * Wraps a Symfony Process running the Codex CLI as a RawResultInterface.
 *
 * @author Johannes Wachter <johannes@sulu.io>
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
     * agent message together with usage data.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        $this->process->wait();

        if (!$this->process->isSuccessful()) {
            throw new RuntimeException(\sprintf('Codex CLI process failed: "%s"', $this->process->getErrorOutput()));
        }

        $output = $this->process->getOutput();
        $lastAgentMessage = [];
        $lastError = [];
        $usage = [];
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

            $type = $decoded['type'] ?? '';

            if ('item.completed' === $type
                && 'agent_message' === ($decoded['item']['type'] ?? '')
            ) {
                $lastAgentMessage = $decoded;
            }

            if ('error' === $type) {
                $lastError = $decoded;
            }

            if ('turn.completed' === $type && isset($decoded['usage'])) {
                $usage = $decoded['usage'];
            }
        }

        if ([] !== $lastError && [] === $lastAgentMessage) {
            return $lastError;
        }

        if ([] !== $usage && [] !== $lastAgentMessage) {
            $lastAgentMessage['usage'] = $usage;
        }

        return $this->attachToolCallTraces($lastAgentMessage, $lastError, $events);
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
            throw new RuntimeException(\sprintf('Codex CLI process failed: "%s"', $this->process->getErrorOutput()));
        }
    }

    public function getObject(): Process
    {
        return $this->process;
    }

    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed>       $lastAgentMessage
     * @param array<string, mixed>       $lastError
     *
     * @return array<string, mixed>
     */
    private function attachToolCallTraces(array $lastAgentMessage, array $lastError, array $events): array
    {
        $toolCallTraces = $this->extractToolCallTraces($events);

        if ([] === $toolCallTraces) {
            return [] !== $lastAgentMessage ? $lastAgentMessage : $lastError;
        }

        if ([] !== $lastAgentMessage) {
            $lastAgentMessage[self::TOOL_CALL_TRACES] = $toolCallTraces;

            return $lastAgentMessage;
        }

        if ([] !== $lastError) {
            $lastError[self::TOOL_CALL_TRACES] = $toolCallTraces;

            return $lastError;
        }

        return [self::TOOL_CALL_TRACES => $toolCallTraces];
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
        $started = [];
        $traces = [];

        foreach ($events as $index => $event) {
            $type = $event['type'] ?? null;
            if (!\is_string($type)) {
                continue;
            }

            $trace = match ($type) {
                'item.started', 'item.completed' => $this->extractLegacyToolCallTrace($event),
                'event_msg' => $this->extractEventMessageToolCallTrace($event),
                default => null,
            };
            if (null === $trace) {
                continue;
            }

            $key = $trace['id'] ?? \sprintf('%s-%d', $trace['name'], $index);

            if ('item.started' === $type || ('event_msg' === $type && ($event['payload']['type'] ?? null) === 'mcp_tool_call_start')) {
                $started[$key] = $trace;
                continue;
            }

            if (isset($started[$key])) {
                $trace = $this->mergeStartedTrace($started[$key], $trace);
                unset($started[$key]);
            }

            $traces[] = $trace;
        }

        foreach ($started as $trace) {
            $traces[] = $trace;
        }

        return $traces;
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{
     *     id: ?string,
     *     name: string,
     *     arguments: array<string, mixed>,
     *     started_at_ms: ?float,
     *     duration_ms: ?float,
     *     errored: bool
     * }|null
     */
    private function extractLegacyToolCallTrace(array $event): ?array
    {
        $item = $event['item'] ?? null;
        if (!\is_array($item) || !$this->isToolCallItem($item)) {
            return null;
        }

        return $this->normalizeToolCallTrace($item, $event);
    }

    /**
     * @param array<string, mixed> $event
     *
     * @return array{
     *     id: ?string,
     *     name: string,
     *     arguments: array<string, mixed>,
     *     started_at_ms: ?float,
     *     duration_ms: ?float,
     *     errored: bool
     * }|null
     */
    private function extractEventMessageToolCallTrace(array $event): ?array
    {
        $payload = $event['payload'] ?? null;
        if (!\is_array($payload)) {
            return null;
        }

        $payloadType = $payload['type'] ?? null;
        if (!\is_string($payloadType) || !\in_array($payloadType, ['mcp_tool_call_start', 'mcp_tool_call_end'], true)) {
            return null;
        }

        $invocation = $payload['invocation'] ?? null;
        if (!\is_array($invocation)) {
            return null;
        }

        $name = $this->extractString($invocation, ['tool']);
        if (null === $name) {
            return null;
        }

        return [
            'id' => $this->extractString($payload, ['call_id']),
            'name' => $name,
            'arguments' => $this->extractToolCallArguments($invocation),
            'started_at_ms' => $this->extractMilliseconds($event, ['timestamp']),
            'duration_ms' => $this->extractDurationMilliseconds($payload['duration'] ?? null),
            'errored' => $this->isEventMessageToolCallErrored($payload),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isToolCallItem(array $item): bool
    {
        $type = $item['type'] ?? null;
        if (!\is_string($type) || '' === $type) {
            return false;
        }

        if (\in_array($type, ['agent_message', 'command_execution'], true)) {
            return false;
        }

        if (str_contains($type, 'tool')) {
            return true;
        }

        if (\in_array($type, ['function_call', 'mcp_call'], true)) {
            return true;
        }

        return isset($item['name']) || isset($item['tool_name']) || isset($item['function']);
    }

    /**
     * @param array<string, mixed> $item
     * @param array<string, mixed> $event
     *
     * @return array{
     *     id: ?string,
     *     name: string,
     *     arguments: array<string, mixed>,
     *     started_at_ms: ?float,
     *     duration_ms: ?float,
     *     errored: bool
     * }|null
     */
    private function normalizeToolCallTrace(array $item, array $event): ?array
    {
        $name = $this->extractToolCallName($item);
        if (null === $name) {
            return null;
        }

        return [
            'id' => $this->extractString($item, ['id', 'call_id']),
            'name' => $name,
            'arguments' => $this->extractToolCallArguments($item),
            'started_at_ms' => $this->extractMilliseconds($item, ['started_at_ms', 'started_at', 'created_at'])
                ?? $this->extractMilliseconds($event, ['started_at_ms', 'started_at', 'created_at', 'timestamp']),
            'duration_ms' => $this->extractFloat($item, ['duration_ms', 'duration']),
            'errored' => true === ($item['is_error'] ?? false)
                || isset($item['error'])
                || \in_array($item['status'] ?? null, ['failed', 'error'], true),
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function extractToolCallName(array $item): ?string
    {
        $name = $this->extractString($item, ['name', 'tool_name', 'tool']);
        if (null !== $name) {
            return $name;
        }

        $function = $item['function'] ?? null;
        if (\is_array($function) && isset($function['name']) && \is_string($function['name']) && '' !== $function['name']) {
            return $function['name'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private function extractToolCallArguments(array $item): array
    {
        foreach (['arguments', 'input'] as $key) {
            if (!isset($item[$key])) {
                continue;
            }

            if (\is_array($item[$key])) {
                return $item[$key];
            }

            if (\is_string($item[$key]) && '' !== $item[$key]) {
                try {
                    $decoded = json_decode($item[$key], true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }

                if (\is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $function = $item['function'] ?? null;
        if (\is_array($function)) {
            return $this->extractToolCallArguments($function);
        }

        return [];
    }

    /**
     * @param array{
     *     id: ?string,
     *     name: string,
     *     arguments: array<string, mixed>,
     *     started_at_ms: ?float,
     *     duration_ms: ?float,
     *     errored: bool
     * } $started
     * @param array{
     *     id: ?string,
     *     name: string,
     *     arguments: array<string, mixed>,
     *     started_at_ms: ?float,
     *     duration_ms: ?float,
     *     errored: bool
     * } $completed
     *
     * @return array{
     *     id: ?string,
     *     name: string,
     *     arguments: array<string, mixed>,
     *     started_at_ms: ?float,
     *     duration_ms: ?float,
     *     errored: bool
     * }
     */
    private function mergeStartedTrace(array $started, array $completed): array
    {
        if ([] === $completed['arguments']) {
            $completed['arguments'] = $started['arguments'];
        }

        if (null === $completed['started_at_ms']) {
            $completed['started_at_ms'] = $started['started_at_ms'];
        }

        $completed['errored'] = $completed['errored'] || $started['errored'];

        return $completed;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isEventMessageToolCallErrored(array $payload): bool
    {
        $result = $payload['result'] ?? null;
        if (!\is_array($result)) {
            return false;
        }

        if (isset($result['Err'])) {
            return true;
        }

        $ok = $result['Ok'] ?? null;
        if (!\is_array($ok)) {
            return false;
        }

        return true === ($ok['isError'] ?? false);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private function extractString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private function extractFloat(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (\is_int($value) || \is_float($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    private function extractDurationMilliseconds(mixed $duration): ?float
    {
        if (!\is_array($duration)) {
            return null;
        }

        $seconds = $duration['secs'] ?? null;
        $nanoseconds = $duration['nanos'] ?? null;

        if (!\is_int($seconds) && !\is_float($seconds) && !\is_int($nanoseconds) && !\is_float($nanoseconds)) {
            return null;
        }

        return (float) ($seconds ?? 0) * 1000.0 + (float) ($nanoseconds ?? 0) / 1000000.0;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $keys
     */
    private function extractMilliseconds(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
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
