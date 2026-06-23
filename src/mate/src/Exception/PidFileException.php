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
 * Exception thrown when a PID file operation fails.
 *
 * @author Security Audit
 */
class PidFileException extends \RuntimeException
{
    public function __construct(string $message)
    {
        parent::__construct($message);
    }
}
