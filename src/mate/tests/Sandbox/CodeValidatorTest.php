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
use Symfony\AI\Mate\Sandbox\CodeValidator;
use Symfony\AI\Mate\Sandbox\Exception\SandboxViolationException;

/**
 * The negative cases are the point of this file.
 *
 * A happy-path test on an allowlist proves almost nothing — the allowlist could be "allow
 * everything" and every positive test would still pass. What has to be proven is the other
 * direction, so every construct the design forbids gets a test that shows it is refused.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class CodeValidatorTest extends TestCase
{
    private CodeValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new CodeValidator();
    }

    /**
     * @dataProvider forbiddenCodeProvider
     */
    public function testRejectsForbiddenConstruct(string $code, string $expectedFragment)
    {
        try {
            $this->validator->validate($code);
            $this->fail(\sprintf('The sandbox accepted forbidden code: %s', $code));
        } catch (SandboxViolationException $e) {
            $this->assertStringContainsString($expectedFragment, $e->getMessage());
        }
    }

    /**
     * One entry per prohibition in the design, plus the escapes each prohibition exists to
     * close.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function forbiddenCodeProvider(): iterable
    {
        yield 'new' => ['$x = new DateTime();', 'creating objects with `new`'];
        yield 'new on a variable class' => ['$c = "DateTime"; $x = new $c();', 'creating objects with `new`'];
        yield 'closure' => ['$f = function () { return 1; }; return 1;', 'closures are not supported'];
        yield 'arrow function' => ['$f = fn () => 1; return 1;', 'arrow functions are not supported'];

        yield 'class definition' => ['class Evil {} return 1;', 'defining classes'];
        yield 'function definition' => ['function evil() { return 1; } return 1;', 'defining functions'];
        yield 'interface definition' => ['interface Evil {} return 1;', 'defining interfaces'];
        yield 'trait definition' => ['trait Evil {} return 1;', 'defining traits'];
        yield 'enum definition' => ['enum Evil {} return 1;', 'defining enums'];

        yield 'static call' => ['return DateTime::createFromFormat("Y", "2026");', 'static method calls are not allowed'];
        yield 'static property' => ['return SomeClass::$registry;', 'static property access is not allowed'];
        yield 'class constant' => ['return DateTime::ATOM;', 'class constants and `::class`'];
        yield 'class name constant' => ['return DateTime::class;', 'class constants and `::class`'];

        yield 'reflection by new' => ['$r = new ReflectionClass("DateTime"); return 1;', 'refers to reflection'];
        yield 'reflection by static call' => ['return ReflectionMethod::createFromMethodName("a::b");', 'refers to reflection'];
        yield 'reflection by method name' => ['return $mate->getReflection();', 'refers to reflection'];
        yield 'reflection by function' => ['return reflection_helper();', 'refers to reflection'];
        yield 'reflection by constant' => ['return ReflectionClass::IS_FINAL;', 'refers to reflection'];

        yield 'unlisted function' => ['return file_get_contents("/etc/passwd");', 'is not on the sandbox allowlist'];
        yield 'unlisted function fully qualified' => ['return \file_get_contents("/etc/passwd");', 'is not on the sandbox allowlist'];
        yield 'call_user_func' => ['return call_user_func("system", "id");', 'is not on the sandbox allowlist'];
        yield 'dynamic function call' => ['$f = "system"; return $f("id");', 'dynamic function calls are not allowed'];
        yield 'array_map needs a callback' => ['return array_map("strtoupper", ["a"]);', 'is not on the sandbox allowlist'];

        yield 'variable variable' => ['$name = "x"; return $$name;', 'variable variables'];

        yield 'eval' => ['return eval("return 1;");', '`eval()` is not allowed'];
        yield 'include' => ['include "/etc/passwd"; return 1;', '`include`/`require` is not allowed'];
        yield 'require' => ['require "/etc/passwd"; return 1;', '`include`/`require` is not allowed'];
        yield 'shell exec' => ['return `id`;', 'backtick shell execution'];

        yield 'superglobal GLOBALS' => ['return $GLOBALS["x"];', 'superglobal'];
        yield 'superglobal _SERVER' => ['return $_SERVER["PATH"];', 'superglobal'];
        yield 'superglobal _ENV' => ['return $_ENV["HOME"];', 'superglobal'];
        yield 'superglobal _GET' => ['return $_GET["q"];', 'superglobal'];
        yield 'superglobal _POST' => ['return $_POST["q"];', 'superglobal'];
        yield 'superglobal _COOKIE' => ['return $_COOKIE["q"];', 'superglobal'];
        yield 'superglobal _FILES' => ['return $_FILES["q"];', 'superglobal'];
        yield 'superglobal _REQUEST' => ['return $_REQUEST["q"];', 'superglobal'];
        yield 'superglobal _SESSION' => ['return $_SESSION["q"];', 'superglobal'];

        yield 'property fetch' => ['return $mate->allowedCommands;', 'property access is not allowed'];
        yield 'nullsafe property fetch' => ['return $mate?->allowedCommands;', 'property access is not allowed'];
        yield 'nullsafe method call' => ['return $mate?->runCommand("ls");', 'nullsafe method calls are not allowed'];

        yield 'method call on another variable' => ['$other = 1; return $other->run();', 'only allowed on `$mate`'];
        yield 'chained method call' => ['return $mate->runCommand("ls")->format();', 'only allowed on `$mate`'];
        yield 'dynamic method name' => ['$m = "runCommand"; return $mate->$m("ls");', 'dynamic method names are not allowed'];
        yield 'unknown mate method' => ['return $mate->readFile("/etc/passwd");', 'has no method `readFile()`'];

        yield 'argument unpacking' => ['$a = ["ls"]; return $mate->runCommand(...$a);', 'argument unpacking'];

        yield 'echo' => ['echo "leak";', 'no output stream'];
        yield 'print' => ['print "leak";', 'no output stream'];
        yield 'exit' => ['exit(1);', '`exit`/`die` is not allowed'];
        yield 'global' => ['global $x; return 1;', 'no global scope'];
        yield 'static variable' => ['static $x = 1; return $x;', 'static variables are not allowed'];
        yield 'try catch' => ['try { return 1; } catch (Throwable $e) { return 2; }', 'try/catch is not supported'];
        yield 'switch' => ['switch (1) { case 1: return 1; } return 0;', '`switch` is not supported'];
        yield 'match' => ['return match (1) { 1 => "a", default => "b" };', '`match` is not supported'];
        yield 'instanceof' => ['return $mate instanceof stdClass;', '`instanceof` is not allowed'];
        yield 'clone' => ['$a = clone $mate; return 1;', '`clone` is not allowed'];
        yield 'throw' => ['throw new Exception("x");', '`throw` is not allowed'];
        yield 'error suppression' => ['return @count([]);', 'error-suppression operator'];
        yield 'cast' => ['return (int) "5";', 'type casts are not supported'];
        yield 'isset' => ['$a = []; return isset($a["x"]);', '`isset()` is not supported'];
        yield 'empty' => ['$a = []; return empty($a);', '`empty()` is not supported'];
        yield 'unset' => ['$a = 1; unset($a); return 1;', '`unset()` is not supported'];
        yield 'list destructuring' => ['[$a, $b] = [1, 2]; return $a;', 'destructuring is not supported'];
        yield 'generator' => ['yield 1;', 'generators are not allowed'];

        yield 'closing the php tag' => ['$a = 1; ?> plain text', 'closing the PHP tag'];
        yield 'namespace' => ['namespace Evil; return 1;', 'is not on the sandbox allowlist'];
        yield 'use import' => ['use DateTime; return 1;', 'is not on the sandbox allowlist'];
        yield 'goto' => ['goto end; end: return 1;', 'is not on the sandbox allowlist'];
        yield 'declare' => ['declare(ticks=1); return 1;', 'is not on the sandbox allowlist'];

        yield 'reference assignment' => ['$a = 1; $b = &$a; return $b;', 'assignment by reference'];
        yield 'foreach by reference' => ['$a = [1]; foreach ($a as &$v) { $v = 2; } return $a;', 'iterating by reference'];

        yield 'arbitrary constant' => ['return PHP_INT_MAX;', 'is not available'];
        yield 'magic constant' => ['return __FILE__;', 'is not on the sandbox allowlist'];
    }

    /**
     * @dataProvider allowedCodeProvider
     */
    public function testAcceptsAllowedConstruct(string $code)
    {
        $this->validator->validate($code);

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function allowedCodeProvider(): iterable
    {
        yield 'literals' => ['return [1, 1.5, "s", true, false, null];'];
        yield 'arithmetic' => ['$a = 1 + 2 * 3 - 4 / 2 % 3; return $a;'];
        yield 'comparison and logic' => ['$a = 1; return $a > 0 && $a !== 2 || false;'];
        yield 'concatenation' => ['$a = "x"; return $a."y";'];
        yield 'interpolation' => ['$a = 5; return "took {$a}ms";'];
        yield 'if elseif else' => ['$a = 1; if ($a > 1) { return "big"; } elseif ($a === 1) { return "one"; } else { return "small"; }'];
        yield 'for loop' => ['$t = 0; for ($i = 0; $i < 3; $i++) { $t += $i; } return $t;'];
        yield 'foreach loop' => ['$t = 0; foreach ([1, 2] as $k => $v) { $t += $v; } return $t;'];
        yield 'while loop' => ['$i = 0; while ($i < 3) { $i++; } return $i;'];
        yield 'do while loop' => ['$i = 0; do { $i++; } while ($i < 3); return $i;'];
        yield 'break and continue' => ['$t = 0; for ($i = 0; $i < 5; $i++) { if ($i === 1) { continue; } if ($i === 3) { break; } $t += $i; } return $t;'];
        yield 'array append and access' => ['$a = []; $a[] = 1; $a["k"] = 2; return $a["k"];'];
        yield 'ternary' => ['$a = 1; return $a > 0 ? "yes" : "no";'];
        yield 'allowed functions' => ['return [count([1]), sprintf("%d", 1), min(1, 2), max(1, 2), round(1.234, 2), array_sum([1, 2]), strlen("ab"), substr("abc", 1), str_contains("abc", "b"), number_format(1234.5, 2)];'];
        yield 'mate call' => ['return $mate->runCommand("bin/console list");'];
        yield 'comment only alongside code' => ["// a comment\n\$a = 1;\nreturn \$a;"];
    }

    /**
     * The snippet the whole prototype exists for has to survive the allowlist, or the
     * allowlist is set wrong.
     */
    public function testAcceptsTheMotivatingExample()
    {
        $this->validator->validate(self::motivatingExample());

        $this->addToAssertionCount(1);
    }

    /**
     * Every PHP block in the skill is a snippet an agent will copy. If one of them no longer
     * validates, the skill teaches a call that fails.
     */
    public function testAcceptsEverySnippetInTheSkill()
    {
        $skill = (string) file_get_contents(__DIR__.'/../../skills/symfony-sandbox-execute/SKILL.md');

        preg_match_all('/```php\n(.*?)```/s', $skill, $matches);

        $this->assertNotSame([], $matches[1], 'The skill carries no PHP examples any more.');

        foreach ($matches[1] as $index => $snippet) {
            $this->validator->validate($snippet);
            $this->addToAssertionCount(1);
        }
    }

    public function testRejectsEmptyCode()
    {
        $this->expectException(SandboxViolationException::class);
        $this->expectExceptionMessage('the snippet is empty');

        $this->validator->validate("  \n ");
    }

    public function testRejectsCodeAboveTheSizeLimit()
    {
        $this->expectException(SandboxViolationException::class);
        $this->expectExceptionMessage('Sandbox code is glue, not a program');

        $this->validator->validate(str_repeat('$a = 1; ', 1 + (int) (CodeValidator::MAX_CODE_LENGTH / 8)));
    }

    public function testRejectsCodeThatDoesNotParse()
    {
        $this->expectException(SandboxViolationException::class);
        $this->expectExceptionMessage('not valid PHP');

        $this->validator->validate('$a = ;');
    }

    public function testReportsTheOffendingLine()
    {
        try {
            $this->validator->validate("\$a = 1;\n\$b = 2;\n\$c = new DateTime();");
            $this->fail('The sandbox accepted an object creation.');
        } catch (SandboxViolationException $e) {
            $this->assertSame(3, $e->getSourceLine());
            $this->assertStringContainsString('on line 3', $e->getMessage());
        }
    }

    public function testAllowedFunctionListStaysSmall()
    {
        // Not style policing: every entry here is a permanent widening of the sandbox, so
        // growing the list should be a decision someone makes on purpose.
        $this->assertLessThanOrEqual(15, \count(CodeValidator::ALLOWED_FUNCTIONS));
    }

    public static function motivatingExample(): string
    {
        return <<<'PHP'
            $budget = 100;
            $total = 0;
            $slowest = 0;

            for ($i = 0; $i < 10; $i++) {
                $run = $mate->runCommand('bin/console app:import');

                if ($run['exit_code'] !== 0) {
                    return ['ok' => false, 'reason' => sprintf('run %d exited with %d', $i + 1, $run['exit_code'])];
                }

                $total += $run['duration_ms'];

                if ($run['duration_ms'] > $slowest) {
                    $slowest = $run['duration_ms'];
                }
            }

            $average = round($total / 10, 2);

            return ['ok' => $average < $budget, 'average_ms' => $average, 'slowest_ms' => $slowest];
            PHP;
    }
}
