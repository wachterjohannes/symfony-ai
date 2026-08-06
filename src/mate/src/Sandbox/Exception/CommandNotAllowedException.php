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

/**
 * Thrown when sandboxed code asks `$mate->runCommand()` for a command that is not on the
 * project's allowlist. The allowlist is exact-match and empty by default, so a project
 * that has not opted in can run nothing at all.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class CommandNotAllowedException extends SandboxRuntimeException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(string $command, array $allowed)
    {
        parent::__construct(\sprintf(
            'Command "%s" is not allowed. The sandbox runs only commands listed verbatim in the "mate.sandbox.allowed_commands" parameter (%s).',
            $command,
            [] === $allowed ? 'currently empty' : '"'.implode('", "', $allowed).'"',
        ));
    }
}
