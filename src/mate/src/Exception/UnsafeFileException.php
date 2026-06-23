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
 * Exception thrown when an unsafe file path is detected.
 *
 * @author Security Audit
 */
class UnsafeFileException extends \RuntimeException
{
    public function __construct(string $path, string $reason = 'Path contains unsafe sequences')
    {
        parent::__construct(sprintf('Unsafe file path detected: %s. Reason: %s', $path, $reason));
    }
}
