<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Runtime\InvocationPhpVersionProbe;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class InvocationPhpVersionProbeTest extends TestCase
{
    public function testTheBareBinaryIsNotWrapped()
    {
        $probe = new InvocationPhpVersionProbe();

        $this->assertFalse($probe->isWrapped('vendor/bin/mate'));
    }

    public function testACustomBinaryPathAloneIsNotWrapped()
    {
        $probe = new InvocationPhpVersionProbe();

        $this->assertFalse($probe->isWrapped('bin/mate'));
    }

    public function testAWrapperInFrontOfTheBinaryIsWrapped()
    {
        $probe = new InvocationPhpVersionProbe();

        $this->assertTrue($probe->isWrapped('ddev exec vendor/bin/mate'));
    }

    public function testDetectingTheBareBinaryNeverRunsTheRunner()
    {
        $probe = new InvocationPhpVersionProbe(function (array $command): string {
            $this->fail('The runner must not be invoked for a bare binary.');
        });

        $this->assertNull($probe->detect('vendor/bin/mate'));
    }

    public function testDetectsTheVersionReportedByTheWrapper()
    {
        $probe = new InvocationPhpVersionProbe(static function (array $command): string {
            return '8.3';
        });

        $this->assertSame('8.3', $probe->detect('ddev exec vendor/bin/mate'));
    }

    public function testNormalizesNoiseAroundTheReportedVersion()
    {
        $probe = new InvocationPhpVersionProbe(static function (array $command): string {
            return "Starting container...\n8.3.11-cli\n";
        });

        $this->assertSame('8.3', $probe->detect('ddev exec vendor/bin/mate'));
    }

    public function testReturnsNullWhenTheRunnerFails()
    {
        $probe = new InvocationPhpVersionProbe(static function (array $command): ?string {
            return null;
        });

        $this->assertNull($probe->detect('ddev exec vendor/bin/mate'));
    }

    public function testReturnsNullWhenTheOutputDoesNotParseAsAVersion()
    {
        $probe = new InvocationPhpVersionProbe(static function (array $command): string {
            return 'command not found';
        });

        $this->assertNull($probe->detect('ddev exec vendor/bin/mate'));
    }

    public function testAppendsAPhpProbeToAWrapperWithoutAnInterpreter()
    {
        $seenCommand = null;
        $probe = new InvocationPhpVersionProbe(static function (array $command) use (&$seenCommand): string {
            $seenCommand = $command;

            return '8.3';
        });

        $probe->detect('ddev exec vendor/bin/mate');

        $this->assertSame(['ddev', 'exec', 'php', '-r', 'echo \PHP_MAJOR_VERSION.".".\PHP_MINOR_VERSION;'], $seenCommand);
    }

    /**
     * "docker compose exec app php bin/mate" already names "php" as the interpreter that would
     * have run the binary; appending another "php" would run "... php php -r ...", which fails.
     */
    public function testDoesNotDoubleUpAnInterpreterAlreadyNamedInTheWrapper()
    {
        $seenCommand = null;
        $probe = new InvocationPhpVersionProbe(static function (array $command) use (&$seenCommand): string {
            $seenCommand = $command;

            return '8.3';
        });

        $probe->detect('docker compose exec app php bin/mate');

        $this->assertSame(['docker', 'compose', 'exec', 'app', 'php', '-r', 'echo \PHP_MAJOR_VERSION.".".\PHP_MINOR_VERSION;'], $seenCommand);
    }

    public function testDoesNotDoubleUpAVersionedInterpreter()
    {
        $seenCommand = null;
        $probe = new InvocationPhpVersionProbe(static function (array $command) use (&$seenCommand): string {
            $seenCommand = $command;

            return '8.3';
        });

        $probe->detect('symfony php8.3 vendor/bin/mate');

        $this->assertSame(['symfony', 'php8.3', '-r', 'echo \PHP_MAJOR_VERSION.".".\PHP_MINOR_VERSION;'], $seenCommand);
    }
}
