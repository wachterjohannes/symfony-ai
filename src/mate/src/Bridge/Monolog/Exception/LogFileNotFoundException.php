<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Monolog\Exception;

use Symfony\AI\Mate\Exception\InvalidArgumentException;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 *
 * @internal
 */
class LogFileNotFoundException extends InvalidArgumentException
{
    public function __construct(string $path, ?\Throwable $previous = null)
    {
        parent::__construct(\sprintf('Log file not found: "%s"', $path), 0, $previous);
    }
}
