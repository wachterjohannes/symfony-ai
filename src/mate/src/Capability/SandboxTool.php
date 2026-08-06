<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Capability;

use Symfony\AI\Mate\Attribute\AsTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use Symfony\AI\Mate\Sandbox\CodeValidator;
use Symfony\AI\Mate\Sandbox\SandboxRunner;

/**
 * Runs a short PHP snippet against the `$mate` interface instead of making the agent
 * orchestrate one tool call per step.
 *
 * "Is this command under 100ms on average over ten runs" is ten tool calls plus arithmetic
 * the agent does in its head today, or a purpose-built aggregation tool nobody wrote. As a
 * snippet it is five lines, and the averaging happens in PHP where it cannot be
 * hallucinated.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SandboxTool
{
    private readonly CodeValidator $validator;
    private readonly SandboxRunner $runner;

    public function __construct(
        ?CodeValidator $validator = null,
        ?SandboxRunner $runner = null,
    ) {
        // Defaulted rather than required so an unwired instantiation still gets the safe
        // configuration: an empty command allowlist runs nothing.
        $this->validator = $validator ?? new CodeValidator();
        $this->runner = $runner ?? new SandboxRunner();
    }

    /**
     * @param string $code PHP statements without the opening tag, e.g. `$r = $mate->runCommand('...'); return $r['duration_ms'];`
     */
    #[AsTool(name: 'sandbox-execute', title: 'Sandbox Execute', description: 'Run a short PHP snippet against the $mate interface and return what it returns. Use it to compose several steps into one call — repeat a command N times and average, compare two measurements, aggregate — instead of orchestrating one tool call per step. The snippet is checked against a strict allowlist (no new, no closures, no static access, no superglobals, only a fixed list of pure functions) and then runs in a locked-down subprocess. $mate exposes runCommand(string $command) for commands on the project allowlist. Read the sandbox-execute skill for the exact grammar before writing code.')]
    public function execute(string $code): string
    {
        $this->validator->validate($code);

        return ResponseEncoder::encode($this->runner->run($code)->toArray());
    }
}
