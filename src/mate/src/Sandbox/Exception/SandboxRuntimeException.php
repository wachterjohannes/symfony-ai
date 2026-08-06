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

use Symfony\AI\Mate\Exception\RuntimeException;

/**
 * Thrown when validated code fails while running in the sandbox subprocess:
 * a timeout, a killed process, a broken control channel, or an error raised
 * by the code itself.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class SandboxRuntimeException extends RuntimeException
{
}
