<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Sandbox\Exception;

use Symfony\AI\Mate\Exception\InvalidArgumentException;

/**
 * Thrown when submitted code contains a construct the sandbox does not allow.
 *
 * The message always carries the source line and the reason, because the agent that
 * submitted the code is the one that has to fix it.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SandboxViolationException extends InvalidArgumentException
{
    public function __construct(
        private readonly string $reason,
        private readonly int $sourceLine = 0,
    ) {
        parent::__construct($sourceLine > 0
            ? \sprintf('Sandbox rejected the code on line %d: %s', $sourceLine, $reason)
            : \sprintf('Sandbox rejected the code: %s', $reason));
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /**
     * The line inside the submitted snippet, not inside this file — \Exception::getLine()
     * is final and reports the latter.
     */
    public function getSourceLine(): int
    {
        return $this->sourceLine;
    }
}
