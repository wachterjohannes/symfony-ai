---
name: symfony-sandbox-execute
description: Reference for writing PHP snippets for the sandbox-execute tool — the $mate methods, the allowed grammar, and what is rejected. Use when a question needs several steps composed (repeat N times and average, compare two runs, aggregate) rather than one tool call, and read it before writing the snippet so the allowlist does not reject it.
---

# Sandbox execute

`sandbox-execute` takes a short PHP snippet and returns whatever it returns. It exists for
the questions that are awkward as tool calls: *"is this command under 100ms averaged over
ten runs"* is ten calls plus arithmetic done in your head, or five lines of PHP where the
arithmetic cannot be misremembered.

This is reference material, not a workflow. Compose freely inside the grammar below.

```
mate tools:call sandbox-execute --code='$r = $mate->runCommand("bin/console cache:warmup"); return $r["duration_ms"];'
```

The snippet is statements without `<?php`. Whatever you `return` is the result. There is no
output stream — `echo` is rejected, so returning is the only way to see anything.

The response is `{result, mate_calls, duration_ms}`: your return value, how many `$mate`
calls the snippet made, and how long the whole run took.

## The `$mate` interface

`$mate` is the only object that exists, and the only way out of the sandbox. It has no
properties, only methods.

### `runCommand(string $command): array`

Runs one command and reports how it went.

```
['exit_code' => int, 'duration_ms' => int, 'output_tail' => string, 'truncated' => bool]
```

- The command has to appear **verbatim** in the project's `mate.sandbox.allowed_commands`
  parameter. The list is empty by default, so on a project that has not opted in every call
  fails — that is the intended default, not a misconfiguration.
- Exact match means v1 cannot run a command with arguments you choose. `bin/console
  app:import` is either on the list or it is not; you cannot append `--limit=5` to it.
- The command runs without a shell. No pipes, no redirection, no globbing, no `&&`.
- `output_tail` is the last 2000 bytes of stdout and stderr combined, with `truncated`
  saying whether anything was cut.
- A command that is not allowed, or that exceeds the command timeout, aborts the whole
  snippet. You cannot catch it — see below.

## The grammar

Everything not listed here is rejected. The rejection names the line and the reason, so a
failed run tells you what to change.

**Allowed**

- Literals: integers, floats, strings, `true`, `false`, `null`, array literals, string
  interpolation (`"took {$run['duration_ms']}ms"`).
- Arithmetic, comparison, logical and concatenation operators; `++`/`--`; ternaries.
- `if` / `elseif` / `else`, `for`, `foreach`, `while`, `do-while`, `break`, `continue`.
- Variable assignment (including `+=` and friends), array access, `$a[] = …`.
- `return`, anywhere, including out of a loop.
- Method calls on `$mate` and nothing else.
- These functions, and no others:
  `count`, `sprintf`, `min`, `max`, `round`, `array_sum`, `strlen`, `substr`,
  `str_contains`, `number_format`.

**Rejected**

- `new`, and therefore every object but `$mate`.
- Closures and arrow functions. This is the v1 limitation you will notice first: no
  `array_map`, no `usort`, no callback of any kind. Write the loop out.
- Defining classes, functions, interfaces, traits or enums.
- Static calls, static properties, class constants, `::class` — and anything whose name
  contains "reflection", checked separately.
- Any function not on the list above, and any call whose function name is not written out
  literally (`$f()`, `call_user_func(…)`).
- `$$variable`, superglobals (`$_SERVER`, `$_ENV`, `$GLOBALS`, …), `eval`, `include`,
  `require`, backticks.
- Property access and nullsafe calls, including on `$mate`.
- `try`/`catch`, `switch`, `match`, `isset`, `empty`, casts, `throw`, `exit`.

No `try`/`catch` is deliberate: if a `$mate` call fails, the run stops and you get the
error. Check the values you get back (`exit_code`) rather than trying to catch anything.

## Limits

The snippet runs in its own PHP subprocess with 32 MB of memory and a 10 second wall clock
for the whole run, commands included. A loop that never ends is killed, and you get the
timeout back as the error. Keep the loop counts small enough that N commands fit in the
budget.

## Worked example

The question this tool was built for — *"does this command stay under 100ms on average over
ten runs?"*:

```php
$budget = 100;
$total = 0;
$slowest = 0;

for ($i = 0; $i < 10; $i++) {
    $run = $mate->runCommand('bin/console app:import');

    if ($run['exit_code'] !== 0) {
        return [
            'ok' => false,
            'reason' => sprintf('run %d exited with %d', $i + 1, $run['exit_code']),
            'output' => $run['output_tail'],
        ];
    }

    $total += $run['duration_ms'];

    if ($run['duration_ms'] > $slowest) {
        $slowest = $run['duration_ms'];
    }
}

$average = round($total / 10, 2);

return [
    'ok' => $average < $budget,
    'average_ms' => $average,
    'slowest_ms' => $slowest,
    'budget_ms' => $budget,
];
```

Ten runs, one call, and the average is computed in PHP rather than estimated.

## Other shapes worth stealing

Warm versus cold, without doing the subtraction yourself:

```php
$first = $mate->runCommand('bin/console cache:warmup');
$second = $mate->runCommand('bin/console cache:warmup');

return [
    'cold_ms' => $first['duration_ms'],
    'warm_ms' => $second['duration_ms'],
    'saved_ms' => $first['duration_ms'] - $second['duration_ms'],
];
```

Is it flaky? Run it until it fails, and report which run broke:

```php
$failures = [];

for ($i = 0; $i < 5; $i++) {
    $run = $mate->runCommand('vendor/bin/phpunit');

    if ($run['exit_code'] !== 0) {
        $failures[] = $i + 1;
    }
}

return ['runs' => 5, 'failed_runs' => $failures, 'flaky' => count($failures) > 0 && count($failures) < 5];
```

## When not to use this

- One command, one answer: call the command's own tool, or run it directly.
- Anything needing a callback, a class, or a library — the grammar has none of that, and
  fighting the allowlist costs more than the loop saves.
- Reading files, calling APIs, touching the network: the sandbox cannot, by design. There is
  no flag that turns it on.
