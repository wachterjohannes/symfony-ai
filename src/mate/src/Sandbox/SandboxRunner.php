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

use Symfony\AI\Mate\Sandbox\Exception\SandboxRuntimeException;

/**
 * Second sandbox layer: runs validated code in a PHP subprocess that has been stripped of
 * everything it could otherwise reach.
 *
 * The two layers are deliberately independent. {@see CodeValidator} decides what the code
 * may *say*; this class decides what the process running it may *do*. A gap in the AST
 * allowlist still lands in a process without `proc_open`, without `fopen`, without the
 * network, capped in memory and killed on a wall clock. Neither layer is asked to be
 * airtight on its own.
 *
 * The subprocess also holds no Mate code at all — `$mate` inside it is a proxy over a pipe,
 * and every actual capability stays here in the parent, behind {@see MateApi}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SandboxRunner
{
    /**
     * SIGKILL as a literal: the constant only exists when ext-pcntl is loaded, and killing a
     * runaway sandbox must not depend on an optional extension.
     */
    private const SIGKILL = 9;

    /**
     * Hard-disabled in the subprocess. Every one of these is already unreachable through
     * the AST allowlist — that is the point. If the allowlist ever lets something through,
     * this list is what the code lands in.
     *
     * @var list<string>
     */
    private const DISABLED_FUNCTIONS = [
        // process execution
        'exec', 'shell_exec', 'system', 'passthru', 'popen', 'pclose', 'proc_open',
        'proc_close', 'proc_terminate', 'proc_get_status', 'proc_nice',
        'pcntl_exec', 'pcntl_fork', 'pcntl_signal', 'posix_kill',
        // filesystem
        'file_get_contents', 'file_put_contents', 'fopen', 'readfile', 'fpassthru',
        'tmpfile', 'tempnam', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'touch',
        'chmod', 'chown', 'chgrp', 'symlink', 'link', 'scandir', 'glob', 'opendir',
        'readdir', 'dir', 'parse_ini_file', 'parse_ini_string', 'set_include_path',
        // network
        'curl_init', 'curl_exec', 'curl_multi_exec', 'curl_setopt', 'fsockopen',
        'pfsockopen', 'stream_socket_client', 'stream_socket_server', 'socket_create',
        'mail',
        // runtime and environment introspection
        'dl', 'putenv', 'getenv', 'ini_set', 'ini_alter', 'ini_restore', 'set_time_limit',
        'phpinfo', 'php_uname', 'error_log', 'syslog', 'highlight_file', 'show_source',
        'get_defined_vars', 'get_defined_functions', 'get_defined_constants',
        'get_declared_classes', 'get_class_methods',
        // dynamic dispatch and (de)serialization
        'call_user_func', 'call_user_func_array', 'forward_static_call',
        'forward_static_call_array', 'serialize', 'unserialize', 'extract', 'compact',
        // burning the clock
        'sleep', 'usleep', 'time_nanosleep', 'time_sleep_until',
    ];

    /**
     * @param int $timeout       Wall-clock seconds for the whole run, enforced here and
     *                           re-declared to the subprocess as `max_execution_time`
     * @param int $memoryLimitMb Subprocess memory cap
     */
    public function __construct(
        private readonly MateApi $api = new MateApi(),
        private readonly int $timeout = 10,
        private readonly int $memoryLimitMb = 32,
        private readonly ?string $phpBinary = null,
    ) {
    }

    /**
     * Runs already-validated code. Passing unvalidated code here is a bug: the process
     * hardening is the second bar, not the first.
     */
    public function run(string $code): SandboxResult
    {
        $runnerPath = \dirname(__DIR__, 2).'/resources/sandbox/runner.php';
        if (!is_file($runnerPath)) {
            throw new SandboxRuntimeException(\sprintf('The sandbox runner script is missing at "%s".', $runnerPath));
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $startedAt = hrtime(true);
        $process = @proc_open($this->buildCommand($runnerPath), $descriptors, $pipes);
        if (!\is_resource($process)) {
            throw new SandboxRuntimeException('The sandbox subprocess could not be started.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        try {
            return $this->converse($process, $pipes, $code, $startedAt);
        } finally {
            $this->shutdown($process, $pipes);
        }
    }

    /**
     * @param resource                                     $process
     * @param array{0: resource, 1: resource, 2: resource} $pipes
     */
    private function converse($process, array $pipes, string $code, int $startedAt): SandboxResult
    {
        $deadline = microtime(true) + $this->timeout;
        $buffer = '';
        $calls = 0;
        $pendingFailure = null;

        $this->send($pipes[0], ['code' => $code]);

        while (true) {
            $line = $this->readLine($pipes[1], $buffer, $deadline, $process);

            if (null === $line) {
                $stderr = trim((string) stream_get_contents($pipes[2]));

                throw new SandboxRuntimeException(\sprintf('The sandbox subprocess died without returning a result%s. A memory-limit or fatal error usually explains this.', '' === $stderr ? '' : ': '.$stderr));
            }

            $message = json_decode($line, true);
            if (!\is_array($message)) {
                throw new SandboxRuntimeException('The sandbox subprocess sent a message that is not valid JSON.');
            }

            switch ($message['t'] ?? null) {
                case 'call':
                    ++$calls;
                    $pendingFailure = $this->handleCall($pipes[0], $message);
                    break;

                case 'result':
                    return new SandboxResult(
                        $message['value'] ?? null,
                        $calls,
                        (int) round((hrtime(true) - $startedAt) / 1_000_000),
                    );

                case 'error':
                    // A failure raised by $mate is reported through the subprocess, but the
                    // real exception is the one the parent caught — keep it, so the caller
                    // sees CommandNotAllowedException rather than a flattened string.
                    if (null !== $pendingFailure) {
                        throw $pendingFailure;
                    }

                    throw new SandboxRuntimeException(\sprintf('The sandbox code failed with %s: %s', \is_string($message['class'] ?? null) ? $message['class'] : 'an error', \is_string($message['message'] ?? null) ? $message['message'] : 'unknown reason'));
                default:
                    throw new SandboxRuntimeException('The sandbox subprocess sent an unknown message type.');
            }
        }
    }

    /**
     * @param resource             $stdin
     * @param array<string, mixed> $message
     */
    private function handleCall($stdin, array $message): ?\Throwable
    {
        $method = \is_string($message['method'] ?? null) ? $message['method'] : '';
        $arguments = \is_array($message['args'] ?? null) ? array_values($message['args']) : [];

        try {
            $this->send($stdin, ['t' => 'return', 'value' => $this->api->invoke($method, $arguments)]);

            return null;
        } catch (\Throwable $e) {
            $this->send($stdin, ['t' => 'throw', 'message' => $e->getMessage()]);

            return $e;
        }
    }

    /**
     * Reads one newline-terminated message, never blocking past the wall-clock deadline.
     *
     * Returns null on a clean EOF — the subprocess exited without saying anything more.
     *
     * @param resource $stream
     * @param resource $process
     */
    private function readLine($stream, string &$buffer, float $deadline, $process): ?string
    {
        while (true) {
            $newline = strpos($buffer, "\n");
            if (false !== $newline) {
                $line = substr($buffer, 0, $newline);
                $buffer = substr($buffer, $newline + 1);

                return $line;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $this->kill($process);

                throw new SandboxRuntimeException(\sprintf('The sandbox exceeded its %d second wall-clock limit and was killed.', $this->timeout));
            }

            $read = [$stream];
            $write = null;
            $except = null;
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);

            $ready = @stream_select($read, $write, $except, $seconds, $microseconds);
            if (false === $ready) {
                throw new SandboxRuntimeException('Reading from the sandbox subprocess failed.');
            }

            if (0 === $ready) {
                continue;
            }

            $chunk = fread($stream, 8192);
            if (false === $chunk || ('' === $chunk && feof($stream))) {
                return null;
            }

            $buffer .= $chunk;
        }
    }

    /**
     * @param resource             $stdin
     * @param array<string, mixed> $payload
     */
    private function send($stdin, array $payload): void
    {
        $encoded = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
        if (false === $encoded) {
            throw new SandboxRuntimeException('A sandbox message could not be encoded.');
        }

        fwrite($stdin, $encoded."\n");
        fflush($stdin);
    }

    /**
     * @return list<string>
     */
    private function buildCommand(string $runnerPath): array
    {
        return [
            $this->phpBinary ?? \PHP_BINARY,
            '-d', 'memory_limit='.$this->memoryLimitMb.'M',
            '-d', 'max_execution_time='.$this->timeout,
            '-d', 'disable_functions='.implode(',', self::DISABLED_FUNCTIONS),
            '-d', 'open_basedir='.\dirname($runnerPath),
            // No superglobals to populate, and none materialised lazily behind the runner's
            // back after it has unset them.
            '-d', 'variables_order=',
            '-d', 'auto_globals_jit=0',
            '-d', 'display_errors=stderr',
            '-d', 'log_errors=0',
            $runnerPath,
        ];
    }

    /**
     * @param resource $process
     */
    private function kill($process): void
    {
        @proc_terminate($process, self::SIGKILL);
    }

    /**
     * @param resource                                     $process
     * @param array{0: resource, 1: resource, 2: resource} $pipes
     */
    private function shutdown($process, array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (\is_resource($pipe)) {
                @fclose($pipe);
            }
        }

        if (\is_resource($process)) {
            if (@proc_get_status($process)['running']) {
                @proc_terminate($process, self::SIGKILL);
            }

            @proc_close($process);
        }
    }
}
