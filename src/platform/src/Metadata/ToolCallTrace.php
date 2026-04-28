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

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ToolCallTrace
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private readonly ?string $id,
        private readonly string $name,
        private readonly array $arguments = [],
        private readonly ?float $startedAtMs = null,
        private readonly ?float $durationMs = null,
        private readonly bool $errored = false,
    ) {
    }

    /**
     * @param array{
     *     id?: ?string,
     *     name: string,
     *     arguments?: array<string, mixed>,
     *     started_at_ms?: ?float,
     *     duration_ms?: ?float,
     *     errored?: bool
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'] ?? null,
            $data['name'],
            $data['arguments'] ?? [],
            $data['started_at_ms'] ?? null,
            $data['duration_ms'] ?? null,
            $data['errored'] ?? false,
        );
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getStartedAtMs(): ?float
    {
        return $this->startedAtMs;
    }

    public function getDurationMs(): ?float
    {
        return $this->durationMs;
    }

    public function isErrored(): bool
    {
        return $this->errored;
    }
}
