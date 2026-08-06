<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Sandbox;

/**
 * What a sandbox run produced, plus the two numbers that tell an agent whether the run did
 * what it thought it did.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SandboxResult
{
    public function __construct(
        public readonly mixed $value,
        public readonly int $mateCalls,
        public readonly int $durationMs,
    ) {
    }

    /**
     * @return array{result: mixed, mate_calls: int, duration_ms: int}
     */
    public function toArray(): array
    {
        return [
            'result' => $this->value,
            'mate_calls' => $this->mateCalls,
            'duration_ms' => $this->durationMs,
        ];
    }
}
