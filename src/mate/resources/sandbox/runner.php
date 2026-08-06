<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Sandbox subprocess entry point.
 *
 * Deliberately standalone: no Composer autoloader, no Mate class, nothing but this file.
 * The subprocess holds no capability of its own — `$mate` here is a proxy that forwards
 * the call over stdout and blocks for the answer on stdin, so every real action happens
 * back in the parent process, behind MateApi.
 *
 * That is what makes the hardening flags in SandboxRunner affordable: the subprocess can
 * lose proc_open, fopen, file_get_contents and the rest without losing the ability to do
 * its job, because its job is only to compute.
 *
 * Wire format, one JSON object per line:
 *   parent -> child   {"code": "..."}                       (once, first)
 *   child  -> parent  {"t":"call","method":"…","args":[…]}
 *   parent -> child   {"t":"return","value":…} | {"t":"throw","message":"…"}
 *   child  -> parent  {"t":"result","value":…} | {"t":"error","message":"…","class":"…"}
 */

/**
 * The `$mate` the sandboxed snippet sees. Every method call becomes one round trip.
 */
final class MateChannelProxy
{
    /**
     * @param list<mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        sandbox_write(['t' => 'call', 'method' => $method, 'args' => array_values($arguments)]);
        $response = sandbox_read();

        if ('return' === ($response['t'] ?? null)) {
            return $response['value'] ?? null;
        }

        throw new RuntimeException((string) ($response['message'] ?? 'The sandbox control channel failed.'));
    }
}

/**
 * @param array<string, mixed> $payload
 */
function sandbox_write(array $payload): void
{
    $encoded = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE);
    if (false === $encoded) {
        $encoded = json_encode(['t' => 'error', 'class' => 'JsonException', 'message' => 'The sandbox produced a value that cannot be encoded.']);
    }

    fwrite(\STDOUT, $encoded."\n");
    fflush(\STDOUT);
}

/**
 * @return array<string, mixed>
 */
function sandbox_read(): array
{
    $line = fgets(\STDIN);
    if (false === $line) {
        // The parent is gone; there is nothing left to answer to.
        exit(70);
    }

    $decoded = json_decode($line, true);

    return is_array($decoded) ? $decoded : [];
}

/**
 * Drops the superglobals.
 *
 * The AST allowlist already rejects them, but a layer that only holds because the other
 * layer held is not a layer. `auto_globals_jit=0` (set by SandboxRunner) matters here:
 * with the JIT on, unsetting a superglobal it has not materialised yet does nothing,
 * because the next read re-creates it.
 */
function sandbox_drop_superglobals(): void
{
    foreach (['_GET', '_POST', '_COOKIE', '_FILES', '_REQUEST', '_SESSION', '_ENV', '_SERVER'] as $name) {
        unset($GLOBALS[$name]);
    }
}

/**
 * Everything runs in here so the snippet cannot read the runner's own state off `$GLOBALS`.
 */
function sandbox_main(): int
{
    sandbox_drop_superglobals();

    $init = sandbox_read();
    $code = $init['code'] ?? null;

    if (!is_string($code)) {
        sandbox_write(['t' => 'error', 'class' => 'InvalidArgumentException', 'message' => 'The sandbox received no code to run.']);

        return 64;
    }

    /*
     * The snippet runs inside a closure so its variables stay local, and so `$mate` reaches
     * it as a parameter rather than as a global.
     */
    $execute = static function (object $mate) use ($code): mixed {
        return eval($code);
    };

    try {
        sandbox_write(['t' => 'result', 'value' => $execute(new MateChannelProxy())]);
    } catch (Throwable $e) {
        sandbox_write(['t' => 'error', 'class' => $e::class, 'message' => $e->getMessage()]);

        return 65;
    }

    return 0;
}

exit(sandbox_main());
