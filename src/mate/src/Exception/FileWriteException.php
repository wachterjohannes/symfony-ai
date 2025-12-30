<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Exception;

/**
 * @internal
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class FileWriteException extends RuntimeException
{
    public function __construct(string $path, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('Failed to write to log file: %s', $path), 0, $previous);
    }
}
