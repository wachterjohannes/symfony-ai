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
use Symfony\AI\Platform\Bridge\TypeSafe\Factory;
use Symfony\AI\Platform\Classification\BooleanAnswer;
use Symfony\AI\Platform\Classification\BooleanQuestion;
use Symfony\AI\Platform\Classification\ChoiceAnswer;
use Symfony\AI\Platform\Classification\ChoiceQuestion;
use Symfony\AI\Platform\Classification\ScoreAnswer;
use Symfony\AI\Platform\Classification\ScoreQuestion;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Result\ClassificationResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class FactoryTest extends TestCase
{
    public function testItCreatesPlatformWithDefaultSettings()
    {
        $this->assertInstanceOf(Platform::class, Factory::createPlatform('test-api-key'));
    }

    public function testItClassifiesThroughThePlatform()
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $this->assertSame('https://api.typesafe.ai/v1/systemone', $url);

            $body = json_decode($options['body'], true);
            $this->assertSame('jev', $body['model']);
            $this->assertSame('Our checkout is down since this morning and customers cannot pay. Fix this NOW!', $body['state']);
            $this->assertSame(['urgent', 'department', 'frustration'], array_keys($body['questions']));

            return new JsonMockResponse([
                'answers' => [
                    'urgent' => ['type' => 'noul', 'noul' => true],
                    'department' => ['type' => 'choice', 'choice' => 'technical', 'probabilities' => ['billing' => 0.13, 'technical' => 0.87], 'confidence' => 0.82],
                    'frustration' => ['type' => 'score', 'score' => 1.24, 'probabilities' => ['0' => 0.12, '1' => 0.52, '2' => 0.36], 'legend' => ['0' => 'Calm', '1' => 'Frustrated', '2' => 'Very angry'], 'confidence' => null],
                ],
                'usage' => ['input_tokens' => 123, 'output_tokens' => 45],
                'model' => 'jev',
            ]);
        });

        $platform = Factory::createPlatform('test-api-key', $httpClient);

        $deferred = $platform->invoke('jev', 'Our checkout is down since this morning and customers cannot pay. Fix this NOW!', [
            'questions' => [
                'urgent' => new BooleanQuestion('Does this need an immediate response?', 'Explicitly time-sensitive', 'No urgency expressed'),
                'department' => new ChoiceQuestion('Which team should handle this?', ['billing' => 'Payments, invoices, refunds', 'technical' => 'Bugs, outages, integrations']),
                'frustration' => new ScoreQuestion('How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry']),
            ],
        ]);

        $result = $deferred->getResult();
        $this->assertInstanceOf(ClassificationResult::class, $result);

        $urgent = $result->getAnswer('urgent');
        $this->assertInstanceOf(BooleanAnswer::class, $urgent);
        $this->assertTrue($urgent->getValue());

        $department = $result->getAnswer('department');
        $this->assertInstanceOf(ChoiceAnswer::class, $department);
        $this->assertSame('technical', $department->getChoice());

        $frustration = $result->getAnswer('frustration');
        $this->assertInstanceOf(ScoreAnswer::class, $frustration);
        $this->assertSame('Frustrated', $frustration->getLevel());
        $this->assertNull($frustration->getConfidence());

        $tokenUsage = $result->getMetadata()->get('token_usage');
        $this->assertSame(123, $tokenUsage->getPromptTokens());
        $this->assertSame(45, $tokenUsage->getCompletionTokens());
    }
}
