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

use Symfony\Component\Process\Process;

/**
 * Determines the PHP major.minor version that will actually run Mate when the developer's
 * answer to "which command should your coding agent use to run Mate" wraps the binary in a
 * container/multi-PHP prefix (`ddev exec`, `symfony php`, `docker compose exec app php`, ...).
 *
 * The process running `mate init` is not necessarily the process that later runs `mate` for
 * real: a bare `vendor/bin/mate` executes directly, so the current process's own
 * `\PHP_MAJOR_VERSION.'.'.\PHP_MINOR_VERSION` is correct. A wrapped invocation routes through
 * something else entirely (a container, a version manager, ...), so this asks that same
 * wrapper to report the version by actually running `php` through it, instead of trusting the
 * host interpreter that merely happened to run `composer require` and `mate init`.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class InvocationPhpVersionProbe
{
    private const TIMEOUT = 5.0;

    private const PROBE_SCRIPT = 'echo \PHP_MAJOR_VERSION.".".\PHP_MINOR_VERSION;';

    /**
     * @var \Closure(list<string>): (string|null)
     */
    private \Closure $runner;

    /**
     * @param (\Closure(list<string>): (string|null))|null $runner Runs a command and returns its
     *                                                             stdout, or null on any failure.
     *                                                             Defaults to a real subprocess
     *                                                             via {@see Process}; overridable
     *                                                             in tests.
     */
    public function __construct(?\Closure $runner = null)
    {
        $this->runner = $runner ?? self::runProcess(...);
    }

    /**
     * True when the invocation names something in front of the binary (a wrapper), as opposed
     * to a single token that is the binary itself.
     */
    public function isWrapped(string $invocation): bool
    {
        return [] !== $this->wrapperTokens($invocation);
    }

    /**
     * Runs `php` through the invocation's wrapper and reports the `major.minor` it belongs to.
     * Returns null when the wrapper cannot be executed, or its output does not parse as a
     * version; callers should fall back to the current process's own version and warn.
     */
    public function detect(string $invocation): ?string
    {
        $wrapper = $this->wrapperTokens($invocation);
        if ([] === $wrapper) {
            return null;
        }

        $output = ($this->runner)($this->probeCommand($wrapper));
        if (null === $output) {
            return null;
        }

        return $this->normalize($output);
    }

    /**
     * Strips the trailing binary token (`vendor/bin/mate`, or any custom path whose basename
     * contains "mate") from the invocation, leaving only the wrapper in front of it. An
     * invocation with nothing left after that is the bare binary: not wrapped, nothing to probe.
     *
     * @return list<string>
     */
    private function wrapperTokens(string $invocation): array
    {
        $tokens = preg_split('/\s+/', trim($invocation));
        if (false === $tokens || [] === $tokens) {
            return [];
        }

        array_pop($tokens);

        return $tokens;
    }

    /**
     * Appends a `php -r` probe to the wrapper, unless the wrapper already ends with a `php`
     * (or `php8.3`, ...) token: `symfony php` and `docker compose exec app php` already name
     * the interpreter that would otherwise have run the binary, and doubling it up (`symfony
     * php php -r ...`) runs nothing.
     *
     * @param list<string> $wrapper
     *
     * @return list<string>
     */
    private function probeCommand(array $wrapper): array
    {
        $lastToken = basename((string) end($wrapper));
        if (1 === preg_match('/^php(\d+(\.\d+)?)?$/', $lastToken)) {
            return array_merge($wrapper, ['-r', self::PROBE_SCRIPT]);
        }

        return array_merge($wrapper, ['php', '-r', self::PROBE_SCRIPT]);
    }

    /**
     * @param list<string> $command
     */
    private static function runProcess(array $command): ?string
    {
        try {
            $process = new Process($command, null, null, null, self::TIMEOUT);
            $process->run();

            if (!$process->isSuccessful()) {
                return null;
            }

            return $process->getOutput();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Reduces the probe's output to `major.minor`, the same granularity and leading-noise
     * tolerance as {@see PhpVersionGuard::normalize()}, since a wrapper may print container
     * startup output ahead of the probed version.
     */
    private function normalize(string $output): ?string
    {
        if (1 !== preg_match('/^\D*(\d+)\.(\d+)/', trim($output), $matches)) {
            return null;
        }

        return $matches[1].'.'.$matches[2];
    }
}
