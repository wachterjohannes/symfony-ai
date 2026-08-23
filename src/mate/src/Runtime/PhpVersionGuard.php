<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Runtime;

use Symfony\AI\Mate\Exception\PhpVersionMismatchException;

/**
 * Refuses to run when the interpreter is not the one the project recorded.
 *
 * Mate reads the compiled container, the profiler cache and the logs of *this* project, and
 * extensions may behave differently per runtime. A host-side invocation against an application
 * that lives in a container therefore reports something that is not the application under test,
 * which is worse than not answering at all.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class PhpVersionGuard
{
    /**
     * Commands that must stay callable under any interpreter: `init` writes the very
     * configuration this guard reads, and the rest never touch the application.
     */
    private const EXEMPT_COMMANDS = ['init', 'list', 'help', 'completion', '_complete'];

    public function __construct(
        private ?string $expectedVersion,
        private string $invocation,
    ) {
    }

    public function assertMatches(?string $commandName): void
    {
        if (null === $commandName || \in_array($commandName, self::EXEMPT_COMMANDS, true)) {
            return;
        }

        $expected = $this->normalize($this->expectedVersion);
        if (null === $expected) {
            return;
        }

        $running = \PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION;
        if ($running === $expected) {
            return;
        }

        throw new PhpVersionMismatchException(\sprintf('Mate is running under PHP %s but this project expects PHP %s. Run it as "%s". The expected version is the "mate.php_version" parameter in mate/config.php; remove it to disable this check.', \PHP_VERSION, $expected, $this->invocation));
    }

    /**
     * Reduces any version string to `major.minor`, which is the granularity that decides
     * whether an extension behaves the same.
     */
    private function normalize(?string $version): ?string
    {
        if (null === $version || '' === trim($version)) {
            return null;
        }

        if (1 !== preg_match('/^\D*(\d+)\.(\d+)/', trim($version), $matches)) {
            return null;
        }

        return $matches[1].'.'.$matches[2];
    }
}
