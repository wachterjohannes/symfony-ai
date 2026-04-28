<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Metadata;

use Symfony\AI\Platform\Exception\InvalidArgumentException;

/**
 * @implements \IteratorAggregate<int, ToolCallTrace>
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ToolCallTraceCollection implements MergeableMetadataInterface, \IteratorAggregate, \Countable, \JsonSerializable
{
    public const METADATA_KEY = 'tool_call_traces';

    /**
     * @var list<ToolCallTrace>
     */
    private readonly array $traces;

    /**
     * @param list<ToolCallTrace> $traces
     */
    public function __construct(array $traces)
    {
        $this->traces = $traces;
    }

    /**
     * @return list<ToolCallTrace>
     */
    public function all(): array
    {
        return $this->traces;
    }

    public function merge(MergeableMetadataInterface $metadata): self
    {
        if (!$metadata instanceof self) {
            throw new InvalidArgumentException(\sprintf('Cannot merge "%s" with "%s".', self::class, $metadata::class));
        }

        return new self([...$this->traces, ...$metadata->traces]);
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->traces);
    }

    public function count(): int
    {
        return \count($this->traces);
    }

    /**
     * @return list<array{
     *     id: ?string,
     *     name: string,
     *     arguments: array<string, mixed>,
     *     started_at_ms: ?float,
     *     duration_ms: ?float,
     *     errored: bool
     * }>
     */
    public function jsonSerialize(): array
    {
        return array_map(
            static fn (ToolCallTrace $trace): array => [
                'id' => $trace->getId(),
                'name' => $trace->getName(),
                'arguments' => $trace->getArguments(),
                'started_at_ms' => $trace->getStartedAtMs(),
                'duration_ms' => $trace->getDurationMs(),
                'errored' => $trace->isErrored(),
            ],
            $this->traces,
        );
    }
}
