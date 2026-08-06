<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Sandbox;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Capability\SandboxTool;
use Symfony\AI\Mate\Encoding\ResponseEncoder;
use Symfony\AI\Mate\Sandbox\CodeValidator;
use Symfony\AI\Mate\Sandbox\Exception\CommandNotAllowedException;
use Symfony\AI\Mate\Sandbox\Exception\SandboxViolationException;
use Symfony\AI\Mate\Sandbox\MateApi;
use Symfony\AI\Mate\Sandbox\SandboxRunner;

/**
 * The prototype's reason for existing, run end to end.
 *
 * "Does this command stay under 100ms averaged over ten runs" is the question that motivated
 * the whole thing: ten tool calls plus arithmetic the agent does in its head today. Here it
 * goes through the real tool — validator, subprocess, control channel, real commands — and
 * comes back as one number.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SandboxExecuteIntegrationTest extends TestCase
{
    private const RUNS = 10;

    public function testTenRunsAveragedAndComparedToABudgetInASingleCall()
    {
        $result = $this->call($this->benchmarkSnippet(5000));

        $this->assertSame(self::RUNS, $result['mate_calls'], 'The snippet has to have run the command ten times.');

        $report = $result['result'];
        $this->assertCount(self::RUNS, $report['durations_ms']);
        $this->assertSame(self::RUNS, $report['runs']);

        // The average has to be the average of the durations the sandbox actually measured,
        // not a number the model produced — that is the entire point of doing it in PHP.
        $this->assertSame(
            round(array_sum($report['durations_ms']) / self::RUNS, 2),
            $report['average_ms'],
        );
        $this->assertSame(max($report['durations_ms']), $report['slowest_ms']);

        $this->assertTrue($report['ok'], 'Ten short commands should fit inside a 5 second budget.');
        $this->assertSame(5000, $report['budget_ms']);
    }

    /**
     * The same snippet with an impossible budget has to come back false. Asserting only the
     * happy branch would pass just as well against a snippet that always returns true.
     */
    public function testTheBudgetVerdictActuallyDependsOnTheMeasurement()
    {
        $report = $this->call($this->benchmarkSnippet(0))['result'];

        $this->assertFalse($report['ok']);
        $this->assertGreaterThan(0, $report['average_ms']);
    }

    /**
     * A command that is not on the allowlist aborts the whole snippet — there is no
     * try/catch in the grammar to swallow it, on purpose.
     */
    public function testAnUnlistedCommandInsideTheSnippetAbortsTheRun()
    {
        $this->expectException(CommandNotAllowedException::class);
        $this->expectExceptionMessage('is not allowed');

        $this->call('$r = $mate->runCommand("curl https://example.com"); return $r["exit_code"];');
    }

    /**
     * Validation runs before anything is started, so forbidden code never reaches a process
     * at all.
     */
    public function testForbiddenCodeIsRejectedBeforeASubprocessIsStarted()
    {
        $this->expectException(SandboxViolationException::class);
        $this->expectExceptionMessage('creating objects with `new`');

        $this->call('$x = new DateTime(); return 1;');
    }

    public function testTheResponseCarriesTheCallCountAndDuration()
    {
        $result = $this->call('return 1 + 1;');

        $this->assertSame(2, $result['result']);
        $this->assertSame(0, $result['mate_calls']);
        $this->assertGreaterThan(0, $result['duration_ms']);
    }

    /**
     * The exact snippet the skill documents, run for real. If the skill's example stops
     * working the skill is teaching a failing call.
     */
    private function benchmarkSnippet(int $budgetMs): string
    {
        $command = var_export($this->command(), true);

        return <<<PHP
            \$budget = {$budgetMs};
            \$total = 0;
            \$slowest = 0;
            \$durations = [];

            for (\$i = 0; \$i < 10; \$i++) {
                \$run = \$mate->runCommand({$command});

                if (\$run['exit_code'] !== 0) {
                    return ['ok' => false, 'reason' => sprintf('run %d exited with %d', \$i + 1, \$run['exit_code'])];
                }

                \$durations[] = \$run['duration_ms'];
                \$total += \$run['duration_ms'];

                if (\$run['duration_ms'] > \$slowest) {
                    \$slowest = \$run['duration_ms'];
                }
            }

            \$average = round(\$total / 10, 2);

            return [
                'ok' => \$average < \$budget,
                'runs' => 10,
                'durations_ms' => \$durations,
                'average_ms' => \$average,
                'slowest_ms' => \$slowest,
                'budget_ms' => \$budget,
            ];
            PHP;
    }

    /**
     * @return array{result: mixed, mate_calls: int, duration_ms: int}
     */
    private function call(string $code): array
    {
        $tool = new SandboxTool(
            new CodeValidator(),
            new SandboxRunner(new MateApi([$this->command()]), 30),
        );

        $decoded = ResponseEncoder::decode($tool->execute($code));
        \assert(\is_array($decoded));

        return $decoded;
    }

    /**
     * A real command, kept trivial so ten of them fit comfortably in the run budget. No
     * spaces inside the argument: the allowlist matches the whole string and splits it on
     * whitespace into an argv.
     */
    private function command(): string
    {
        return \PHP_BINARY.' -r echo("done");';
    }
}
