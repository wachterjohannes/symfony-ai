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

/**
 * `$mate` is the sandbox's only way out, so its allowlist is the only thing standing
 * between "run a snippet" and "run anything".
 *
 * The commands below are `php -r` one-liners written without spaces, because the allowlist
 * is exact-match on the whole string and the string is split on whitespace into an argv.
 * That constraint is a real v1 limitation, and writing the tests inside it keeps it visible.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class MateApiTest extends TestCase
{
    private const ECHO_OK = ' -r echo("ok");';
    private const EXIT_TWO = ' -r exit(2);';
    private const WRITE_STDERR = ' -r fwrite(STDERR,"to-stderr");';
    private const ECHO_MANY = ' -r echo(str_repeat("x",5000));';
    private const SLEEP_LONG = ' -r sleep(30);';
    private const ECHO_ARGUMENT = ' -r echo($argv[1]); a&&b';

    public function testRunsAnAllowlistedCommand()
    {
        $command = $this->php(self::ECHO_OK);
        $api = new MateApi([$command]);

        $result = $api->runCommand($command);

        $this->assertSame(0, $result['exit_code']);
        $this->assertGreaterThanOrEqual(0, $result['duration_ms']);
        $this->assertSame('ok', $result['output_tail']);
        $this->assertFalse($result['truncated']);
    }

    public function testRejectsACommandThatIsNotOnTheAllowlist()
    {
        $api = new MateApi([$this->php(self::ECHO_OK)]);

        $this->expectException(CommandNotAllowedException::class);
        $this->expectExceptionMessage('is not allowed');

        $api->runCommand('rm -rf /');
    }

    /**
     * The default matters more than it looks: a project that never configures the sandbox
     * gets one that can do nothing, rather than one that inherits the shell.
     */
    public function testAnEmptyAllowlistRunsNothing()
    {
        $api = new MateApi();

        $this->expectException(CommandNotAllowedException::class);
        $this->expectExceptionMessage('currently empty');

        $api->runCommand($this->php(self::ECHO_OK));
    }

    /**
     * Exact match, not prefix match — otherwise the allowlist would only constrain the
     * first word and anything could be appended to an approved command.
     */
    public function testAllowlistMatchesTheWholeCommandNotAPrefix()
    {
        $allowed = $this->php(self::ECHO_OK);
        $api = new MateApi([$allowed]);

        $this->expectException(CommandNotAllowedException::class);

        $api->runCommand($allowed.' ; rm -rf /');
    }

    /**
     * The command runs without a shell, so metacharacters are data. A shell would have
     * treated `&&` as a separator and tried to run what follows.
     */
    public function testShellMetacharactersAreArgumentsNotSyntax()
    {
        $command = $this->php(self::ECHO_ARGUMENT);
        $api = new MateApi([$command]);

        $result = $api->runCommand($command);

        $this->assertSame('a&&b', $result['output_tail']);
    }

    public function testReportsANonZeroExitCode()
    {
        $command = $this->php(self::EXIT_TWO);
        $api = new MateApi([$command]);

        $this->assertSame(2, $api->runCommand($command)['exit_code']);
    }

    public function testCapturesStandardErrorToo()
    {
        $command = $this->php(self::WRITE_STDERR);
        $api = new MateApi([$command]);

        $this->assertStringContainsString('to-stderr', $api->runCommand($command)['output_tail']);
    }

    public function testTruncatesLongOutputAndSaysSo()
    {
        $command = $this->php(self::ECHO_MANY);
        $api = new MateApi([$command]);

        $result = $api->runCommand($command);

        $this->assertTrue($result['truncated']);
        $this->assertSame(2000, \strlen($result['output_tail']));
    }

    public function testKillsACommandThatOverrunsItsTimeout()
    {
        $command = $this->php(self::SLEEP_LONG);
        $api = new MateApi([$command], '.', 1);

        $this->expectException(SandboxRuntimeException::class);
        $this->expectExceptionMessage('exceeded the 1 second command timeout');

        $api->runCommand($command);
    }

    /**
     * The control channel carries a method name in from the subprocess, so the method
     * allowlist has to hold at runtime and not only in the validator.
     */
    public function testInvokeRejectsAMethodThatIsNotExposed()
    {
        $api = new MateApi();

        $this->expectException(SandboxRuntimeException::class);
        $this->expectExceptionMessage('$mate has no method "readFile"');

        $api->invoke('readFile', ['/etc/passwd']);
    }

    public function testInvokeRejectsAMalformedArgument()
    {
        $api = new MateApi();

        $this->expectException(SandboxRuntimeException::class);
        $this->expectExceptionMessage('expects the command as a string');

        $api->invoke('runCommand', [['not', 'a', 'string']]);
    }

    public function testInvokeDispatchesToRunCommand()
    {
        $command = $this->php(self::ECHO_OK);
        $api = new MateApi([$command]);

        $this->assertSame(0, $api->invoke('runCommand', [$command])['exit_code']);
    }

    /**
     * The validator advertises METHODS to the agent, so a name listed there but not
     * dispatched by invoke() would be a method the skill promises and the sandbox refuses.
     */
    public function testEveryAdvertisedMethodIsActuallyDispatched()
    {
        foreach (MateApi::METHODS as $method) {
            try {
                (new MateApi())->invoke($method, ['not-on-any-allowlist']);
                $this->fail(\sprintf('$mate->%s() should have refused an unconfigured call.', $method));
            } catch (SandboxRuntimeException $e) {
                $this->assertStringNotContainsString(
                    'has no method',
                    $e->getMessage(),
                    \sprintf('MateApi::METHODS advertises "%s" to the agent, but invoke() does not dispatch it.', $method),
                );
            }
        }
    }

    private function php(string $arguments): string
    {
        return \PHP_BINARY.$arguments;
    }
}
