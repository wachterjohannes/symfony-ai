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
use Symfony\AI\Mate\Sandbox\Exception\CommandNotAllowedException;
use Symfony\AI\Mate\Sandbox\Exception\SandboxRuntimeException;
use Symfony\AI\Mate\Sandbox\MateApi;
use Symfony\AI\Mate\Sandbox\SandboxRunner;

/**
 * Tests the second layer on its own terms.
 *
 * Every snippet below is deliberately fed to the runner *without* passing the validator
 * first, which is not how the tool works — it is how this layer has to be tested. The
 * question these answer is "if the AST allowlist ever lets something through, what happens
 * then", and it can only be answered by pretending it already did.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SandboxRunnerTest extends TestCase
{
    public function testReturnsWhatTheSnippetReturns()
    {
        $result = (new SandboxRunner())->run('$a = [1, 2, 3]; return ["sum" => array_sum($a), "n" => count($a)];');

        $this->assertSame(['sum' => 6, 'n' => 3], $result->value);
        $this->assertSame(0, $result->mateCalls);
        $this->assertGreaterThan(0, $result->durationMs);
    }

    public function testForwardsMateCallsToTheParentProcess()
    {
        $command = \PHP_BINARY.' -r echo("ok");';
        $runner = new SandboxRunner(new MateApi([$command]));

        $result = $runner->run(\sprintf('$r = $mate->runCommand(%s); return $r["output_tail"];', var_export($command, true)));

        $this->assertSame('ok', $result->value);
        $this->assertSame(1, $result->mateCalls);
    }

    /**
     * A failure inside `$mate` happens in the parent, so the caller should get the parent's
     * exception back rather than a string that went through the pipe and lost its type.
     */
    public function testKeepsTheParentExceptionWhenAMateCallFails()
    {
        $runner = new SandboxRunner(new MateApi());

        $this->expectException(CommandNotAllowedException::class);

        $runner->run('return $mate->runCommand("id");');
    }

    /**
     * @dataProvider escapeAttemptProvider
     */
    public function testTheSubprocessCannotReachTheHost(string $code)
    {
        $runner = new SandboxRunner(timeout: 5);

        $this->expectException(SandboxRuntimeException::class);

        $runner->run($code);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function escapeAttemptProvider(): iterable
    {
        yield 'process execution' => ['return exec("id");'];
        yield 'shell execution' => ['return shell_exec("id");'];
        yield 'system' => ['return system("id");'];
        yield 'passthru' => ['return passthru("id");'];
        yield 'proc_open' => ['$p = proc_open("id", [], $pipes); return "started";'];
        yield 'popen' => ['$h = popen("id", "r"); return "opened";'];
        yield 'reading a file' => ['return file_get_contents("/etc/passwd");'];
        yield 'opening a file' => ['$h = fopen("/etc/passwd", "r"); return "opened";'];
        yield 'writing a file' => ['return file_put_contents("/tmp/mate-sandbox-escape", "x");'];
        yield 'listing a directory' => ['return scandir("/etc");'];
        yield 'globbing' => ['return glob("/etc/*");'];
        yield 'network socket' => ['return fsockopen("127.0.0.1", 80) ? "connected" : "no";'];
        yield 'curl' => ['$c = curl_init("http://127.0.0.1"); return "handle";'];
        yield 'reading the environment' => ['return getenv("HOME");'];
        yield 'changing ini settings' => ['return ini_set("disable_functions", "");'];
        yield 'dynamic dispatch' => ['return call_user_func("phpversion");'];
        yield 'unserialize' => ['return unserialize("b:1;");'];
        yield 'sleeping out the clock' => ['sleep(30); return "awake";'];
    }

    /**
     * The AST layer rejects superglobals; this proves the process layer does not depend on
     * that, which is the only thing that makes it a second layer.
     */
    public function testSuperglobalsAreGoneFromTheSubprocess()
    {
        $runner = new SandboxRunner();

        $this->assertSame('gone', $runner->run('return $_SERVER["PATH"] ?? "gone";')->value);
        $this->assertSame('gone', $runner->run('return $_ENV["HOME"] ?? "gone";')->value);
    }

    /**
     * The runner's own state must not be readable off `$GLOBALS`.
     */
    public function testTheSubprocessGlobalScopeHoldsNothingOfTheRunner()
    {
        $globals = (new SandboxRunner())->run('return array_keys($GLOBALS);')->value;

        $this->assertSame(['argv', 'argc'], $globals);
    }

    public function testFilesOutsideTheRunnerDirectoryAreOutOfReach()
    {
        // include() only warns, so the return value is what proves open_basedir bit.
        $this->assertFalse((new SandboxRunner())->run('return include "/etc/passwd";')->value);
    }

    public function testAWallClockLimitKillsANonTerminatingLoop()
    {
        $runner = new SandboxRunner(timeout: 2);

        $this->expectException(SandboxRuntimeException::class);
        $this->expectExceptionMessage('exceeded its 2 second wall-clock limit');

        $runner->run('while (true) { $x = 1; } return "never";');
    }

    public function testAMemoryLimitStopsAnAllocationLoop()
    {
        $runner = new SandboxRunner(timeout: 10, memoryLimitMb: 16);

        $this->expectException(SandboxRuntimeException::class);
        $this->expectExceptionMessage('died without returning a result');

        $runner->run('$a = []; while (true) { $a[] = str_repeat("x", 100000); } return "never";');
    }

    public function testAnErrorInTheSnippetIsReportedWithItsClass()
    {
        $runner = new SandboxRunner();

        $this->expectException(SandboxRuntimeException::class);
        $this->expectExceptionMessage('DivisionByZeroError');

        $runner->run('return 1 % 0;');
    }
}
