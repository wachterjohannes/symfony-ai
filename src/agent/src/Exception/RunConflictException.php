<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Exception;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class RunConflictException extends RuntimeException
{
    public function __construct(string $id, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('The agent run "%s" was changed by another process, read it again.', $id), 0, $previous);
    }
}
