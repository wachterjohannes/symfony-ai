<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests\StructuredOutput\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\StructuredOutput\Streaming\PartialJsonParser;

final class PartialJsonParserTest extends TestCase
{
    /**
     * @param array<mixed>|scalar|null $expected
     */
    #[DataProvider('provideAlreadyValid')]
    #[DataProvider('provideTrailingCommas')]
    #[DataProvider('provideUnclosedStrings')]
    #[DataProvider('provideIncompleteValues')]
    #[DataProvider('provideUnclosedStructures')]
    #[DataProvider('provideStringsContainingBrackets')]
    public function testParseRecovers(string $input, array|bool|float|int|string|null $expected)
    {
        $errorMessage = 'pre-existing';

        $this->assertSame($expected, PartialJsonParser::parse($input, $errorMessage));
        $this->assertNull($errorMessage);
    }

    public function testParseReturnsNullForUnrecoverableInput()
    {
        $errorMessage = null;

        $this->assertNull(PartialJsonParser::parse('not json at all !!!', $errorMessage));
        $this->assertIsString($errorMessage);
        $this->assertNotSame('', $errorMessage);
    }

    public function testParseWorksWithoutErrorMessageArgument()
    {
        $this->assertSame(['a' => 1], PartialJsonParser::parse('{"a":1}'));
        $this->assertSame(['a' => 'hel'], PartialJsonParser::parse('{"a":"hel'));
    }

    public function testErrorMessageIsResetOnSuccessfulRecovery()
    {
        $errorMessage = 'leftover';

        PartialJsonParser::parse('{"a":1', $errorMessage);

        $this->assertNull($errorMessage);
    }

    /**
     * @return iterable<string, array{string, array<mixed>|scalar|null}>
     */
    public static function provideAlreadyValid(): iterable
    {
        yield 'simple object' => ['{"a":1}', ['a' => 1]];
        yield 'empty object' => ['{}', []];
        yield 'empty array' => ['[]', []];
        yield 'nested' => ['{"a":{"b":[1,2,3]}}', ['a' => ['b' => [1, 2, 3]]]];
        yield 'string scalar' => ['"hello"', 'hello'];
        yield 'numeric scalar' => ['42', 42];
        yield 'boolean true' => ['true', true];
        yield 'null' => ['null', null];
    }

    /**
     * @return iterable<string, array{string, array<mixed>|scalar|null}>
     */
    public static function provideTrailingCommas(): iterable
    {
        yield 'object trailing comma' => ['{"a":1,}', ['a' => 1]];
        yield 'array trailing comma' => ['[1, 2, 3,]', [1, 2, 3]];
        yield 'nested trailing comma' => ['{"a":[1, 2,], "b":3,}', ['a' => [1, 2], 'b' => 3]];
    }

    /**
     * @return iterable<string, array{string, array<mixed>|scalar|null}>
     */
    public static function provideUnclosedStrings(): iterable
    {
        yield 'unclosed value string' => ['{"a":"hel', ['a' => 'hel']];
        yield 'escaped quote inside unclosed string' => ['{"a":"he\"l', ['a' => 'he"l']];
        yield 'unclosed string in array' => ['["foo", "bar', ['foo', 'bar']];
    }

    /**
     * @return iterable<string, array{string, array<mixed>|scalar|null}>
     */
    public static function provideIncompleteValues(): iterable
    {
        yield 'dangling colon' => ['{"a":', ['a' => null]];
        yield 'partial true (tru)' => ['{"a":tru', ['a' => true]];
        yield 'partial true (tr)' => ['{"a":tr', ['a' => true]];
        yield 'partial false (fals)' => ['{"a":fals', ['a' => false]];
        yield 'partial false (fal)' => ['{"a":fal', ['a' => false]];
        yield 'partial false (fa)' => ['{"a":fa', ['a' => false]];
        yield 'partial null (nul)' => ['{"a":nul', ['a' => null]];
        yield 'partial null (nu)' => ['{"a":nu', ['a' => null]];
        yield 'partial literal inside array' => ['[1, tru', [1, true]];
    }

    /**
     * @return iterable<string, array{string, array<mixed>|scalar|null}>
     */
    public static function provideUnclosedStructures(): iterable
    {
        yield 'unclosed nested object' => ['{"a":{"b":1', ['a' => ['b' => 1]]];
        yield 'unclosed nested array' => ['[1, [2, 3', [1, [2, 3]]];
        yield 'mixed object with array of objects' => ['{"items":[{"n":1', ['items' => [['n' => 1]]]];
        yield 'just opened object' => ['{', []];
        yield 'just opened array' => ['[', []];
    }

    /**
     * @return iterable<string, array{string, array<mixed>|scalar|null}>
     */
    public static function provideStringsContainingBrackets(): iterable
    {
        yield 'braces inside string value' => ['{"x":"}{"', ['x' => '}{']];
        yield 'brackets inside string value' => ['{"x":"][", "y":1', ['x' => '][', 'y' => 1]];
        yield 'brackets inside unclosed string' => ['{"x":"hello {world', ['x' => 'hello {world']];
    }
}
