<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\TypeSafe\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\TypeSafe\ResultConverter;
use Symfony\AI\Platform\Bridge\TypeSafe\TokenUsageExtractor;
use Symfony\AI\Platform\Bridge\TypeSafe\TypeSafe;
use Symfony\AI\Platform\Classification\BooleanAnswer;
use Symfony\AI\Platform\Classification\ChoiceAnswer;
use Symfony\AI\Platform\Classification\ScoreAnswer;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Result\ClassificationResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ResultConverterTest extends TestCase
{
    public function testItSupportsTypeSafeModel()
    {
        $this->assertTrue((new ResultConverter())->supports(new TypeSafe('jev')));
    }

    public function testItConvertsAllAnswerTypes()
    {
        $result = (new ResultConverter())->convert($this->createRawResult([
            'answers' => [
                'urgent' => ['type' => 'noul', 'noul' => true],
                'department' => ['type' => 'choice', 'choice' => 'technical', 'probabilities' => ['billing' => 0.13, 'technical' => 0.87], 'confidence' => 0.82],
                'frustration' => ['type' => 'score', 'score' => 1.24, 'probabilities' => ['0' => 0.12, '1' => 0.52, '2' => 0.36], 'legend' => ['0' => 'Calm', '1' => 'Frustrated', '2' => 'Very angry'], 'confidence' => null],
            ],
            'usage' => ['input_tokens' => 123, 'output_tokens' => 45],
            'model' => 'jev',
        ]));

        $this->assertInstanceOf(ClassificationResult::class, $result);
        $this->assertSame(['urgent', 'department', 'frustration'], array_keys($result->getContent()));

        $urgent = $result->getAnswer('urgent');
        $this->assertInstanceOf(BooleanAnswer::class, $urgent);
        $this->assertTrue($urgent->getValue());
        $this->assertNull($urgent->getConfidence());

        $department = $result->getAnswer('department');
        $this->assertInstanceOf(ChoiceAnswer::class, $department);
        $this->assertSame('technical', $department->getChoice());
        $this->assertSame(['billing' => 0.13, 'technical' => 0.87], $department->getProbabilities());
        $this->assertSame(0.82, $department->getConfidence());

        $frustration = $result->getAnswer('frustration');
        $this->assertInstanceOf(ScoreAnswer::class, $frustration);
        $this->assertSame(1.24, $frustration->getScore());
        $this->assertSame([0 => 0.12, 1 => 0.52, 2 => 0.36], $frustration->getProbabilities());
        $this->assertSame([0 => 'Calm', 1 => 'Frustrated', 2 => 'Very angry'], $frustration->getLegend());
        $this->assertSame('Frustrated', $frustration->getLevel());
        $this->assertNull($frustration->getConfidence());
    }

    public function testItConvertsFalseBooleanWithConfidence()
    {
        $result = (new ResultConverter())->convert($this->createRawResult([
            'answers' => ['spam' => ['type' => 'noul', 'noul' => false, 'confidence' => 0.97]],
        ]));

        $spam = $result->getAnswer('spam');
        $this->assertInstanceOf(BooleanAnswer::class, $spam);
        $this->assertFalse($spam->getValue());
        $this->assertSame(0.97, $spam->getConfidence());
    }

    public function testItCastsIntegerProbabilitiesToFloats()
    {
        $result = (new ResultConverter())->convert($this->createRawResult([
            'answers' => ['department' => ['type' => 'choice', 'choice' => 'billing', 'probabilities' => ['billing' => 1, 'technical' => 0], 'confidence' => 1]],
        ]));

        $department = $result->getAnswer('department');
        $this->assertInstanceOf(ChoiceAnswer::class, $department);
        $this->assertSame(['billing' => 1.0, 'technical' => 0.0], $department->getProbabilities());
        $this->assertSame(1.0, $department->getConfidence());
    }

    public function testItThrowsOnServerError()
    {
        $this->expectException(ServerException::class);

        (new ResultConverter())->convert($this->createRawResult(['error' => 'boom'], 500));
    }

    public function testItThrowsOnAuthenticationError()
    {
        $this->expectException(AuthenticationException::class);

        (new ResultConverter())->convert($this->createRawResult(['error' => 'invalid api key'], 401));
    }

    public function testItThrowsWhenResponseDoesNotContainAnswers()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Response does not contain classification answers.');

        (new ResultConverter())->convert($this->createRawResult(['invalid' => 'response']));
    }

    public function testItThrowsOnUnsupportedAnswerType()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported answer type "ranking" for question "priority".');

        (new ResultConverter())->convert($this->createRawResult([
            'answers' => ['priority' => ['type' => 'ranking', 'ranking' => ['a', 'b']]],
        ]));
    }

    public function testItThrowsOnMalformedAnswer()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Malformed answer for question "urgent".');

        (new ResultConverter())->convert($this->createRawResult([
            'answers' => ['urgent' => ['type' => 'noul']],
        ]));
    }

    public function testItProvidesTokenUsageExtractor()
    {
        $this->assertInstanceOf(TokenUsageExtractor::class, (new ResultConverter())->getTokenUsageExtractor());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function createRawResult(array $body, int $statusCode = 200): RawHttpResult
    {
        $httpClient = new MockHttpClient(new JsonMockResponse($body, ['http_code' => $statusCode]));

        return new RawHttpResult($httpClient->request('POST', 'https://api.typesafe.ai/v1/systemone'));
    }
}
