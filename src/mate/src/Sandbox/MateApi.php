<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Sandbox;

use Symfony\AI\Mate\Sandbox\Exception\CommandNotAllowedException;
use Symfony\AI\Mate\Sandbox\Exception\SandboxRuntimeException;

/**
 * The `$mate` object sandboxed code composes against — and the sandbox's entire surface to
 * the outside world.
 *
 * It lives in the *parent* process, never in the sandbox subprocess: the subprocess only
 * holds a proxy that forwards a method name and its arguments over a pipe. That is what
 * lets the subprocess be stripped of every capability, including the ones this class needs
 * to do its job.
 *
 * Two of the standing objections to "code mode" are answered here rather than in the
 * validator. Determinism holds at the level of the primitives: every method below is as
 * deterministic as the equivalent `#[AsTool]`, only their composition is free. And
 * redaction happens inside the methods, with the same logic the tools use, so there is no
 * path that returns raw data just because the caller asked for it in a loop.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class MateApi
{
    /**
     * Methods sandboxed code may call. Enforced twice: {@see CodeValidator} rejects
     * anything else at validation time so the agent gets a useful error, and
     * {@see self::invoke()} rejects it again at runtime so the control channel cannot be
     * used to reach a method the validator did not see.
     *
     * @var list<string>
     */
    public const METHODS = ['runCommand'];

    /**
     * SIGKILL as a literal: the constant only exists when ext-pcntl is loaded.
     */
    private const SIGKILL = 9;

    /**
     * Bytes of combined stdout/stderr kept from a command. Truncation is intentional: the
     * sandbox exists to compute over many runs, and a full log per run would defeat that.
     */
    private const OUTPUT_TAIL_BYTES = 2000;

    /**
     * @param list<string> $allowedCommands  Commands runnable verbatim; empty by default, so
     *                                       a project that has not opted in can run nothing
     * @param string       $workingDirectory Directory commands are executed in
     * @param int          $commandTimeout   Seconds a single command may take
     */
    public function __construct(
        private readonly array $allowedCommands = [],
        private readonly string $workingDirectory = '.',
        private readonly int $commandTimeout = 30,
    ) {
    }

    /**
     * Dispatches a call coming off the sandbox control channel.
     *
     * @param list<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function invoke(string $method, array $arguments): array
    {
        // One arm per entry in self::METHODS; anything else falls through to the throw,
        // which is what keeps the control channel from reaching an unlisted method.
        if ('runCommand' === $method) {
            $command = $arguments[0] ?? null;
            if (!\is_string($command)) {
                throw new SandboxRuntimeException('$mate->runCommand() expects the command as a string.');
            }

            return $this->runCommand($command);
        }

        throw new SandboxRuntimeException(\sprintf('$mate has no method "%s".', $method));
    }

    /**
     * Runs an allowlisted command and reports how it went.
     *
     * The allowlist is exact-match on the whole command string, and the command is executed
     * without a shell, so there is no quoting, globbing or interpolation step an argument
     * could escape through. The cost of that is real and stated in the skill: v1 cannot run
     * a command with agent-chosen arguments.
     *
     * @return array{exit_code: int, duration_ms: int, output_tail: string, truncated: bool}
     */
    public function runCommand(string $command): array
    {
        if (!\in_array($command, $this->allowedCommands, true)) {
            throw new CommandNotAllowedException($command, $this->allowedCommands);
        }

        $argv = preg_split('/\s+/', trim($command), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $argv || [] === $argv) {
            throw new SandboxRuntimeException(\sprintf('Command "%s" is empty after parsing.', $command));
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $startedAt = hrtime(true);
        $process = @proc_open($argv, $descriptors, $pipes, $this->workingDirectory);
        if (!\is_resource($process)) {
            throw new SandboxRuntimeException(\sprintf('Command "%s" could not be started.', $command));
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = microtime(true) + $this->commandTimeout;
        $timedOut = false;

        while (true) {
            $status = proc_get_status($process);
            $output .= (string) stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);

            if (!$status['running']) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $timedOut = true;
                proc_terminate($process, self::SIGKILL);
                break;
            }

            usleep(2000);
        }

        $output .= (string) stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if ($timedOut) {
            throw new SandboxRuntimeException(\sprintf('Command "%s" exceeded the %d second command timeout and was killed.', $command, $this->commandTimeout));
        }

        $truncated = \strlen($output) > self::OUTPUT_TAIL_BYTES;

        return [
            'exit_code' => $exitCode,
            'duration_ms' => $durationMs,
            'output_tail' => $truncated ? substr($output, -self::OUTPUT_TAIL_BYTES) : $output,
            'truncated' => $truncated,
        ];
    }
}
