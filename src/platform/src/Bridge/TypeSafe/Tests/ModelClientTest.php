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
use Symfony\AI\Platform\Bridge\TypeSafe\ModelClient;
use Symfony\AI\Platform\Bridge\TypeSafe\TypeSafe;
use Symfony\AI\Platform\Classification\BooleanQuestion;
use Symfony\AI\Platform\Classification\ChoiceQuestion;
use Symfony\AI\Platform\Classification\QuestionInterface;
use Symfony\AI\Platform\Classification\ScoreQuestion;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ModelClientTest extends TestCase
{
    public function testItSupportsTypeSafeModel()
    {
        $client = new ModelClient(new MockHttpClient(), 'test-key');

        $this->assertTrue($client->supports(new TypeSafe('jev')));
    }

    public function testItDoesNotSupportOtherModels()
    {
        $client = new ModelClient(new MockHttpClient(), 'test-key');

        $this->assertFalse($client->supports(new Model('jev')));
    }

    public function testItSendsExpectedRequest()
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.typesafe.ai/v1/systemone', $url);
            $this->assertContains('Authorization: Bearer test-key', $options['headers']);

            $this->assertSame([
                'model' => 'jev',
                'state' => 'Our checkout is down since this morning and customers cannot pay. Fix this NOW!',
                'questions' => [
                    'urgent' => [
                        'type' => 'noul',
                        'instructions' => 'Does this need an immediate response?',
                        'criteria' => ['true' => 'Explicitly time-sensitive', 'false' => 'No urgency expressed'],
                    ],
                    'department' => [
                        'type' => 'choice',
                        'instructions' => 'Which team should handle this?',
                        'criteria' => ['billing' => 'Payments, invoices, refunds', 'technical' => 'Bugs, outages, integrations'],
                    ],
                    'frustration' => [
                        'type' => 'score',
                        'instructions' => 'How frustrated is the customer?',
                        'criteria' => ['Calm', 'Frustrated', 'Very angry'],
                    ],
                ],
            ], json_decode($options['body'], true));

            return new JsonMockResponse(['answers' => []]);
        });

        $client = new ModelClient($httpClient, 'test-key');
        $client->request(
            new TypeSafe('jev'),
            'Our checkout is down since this morning and customers cannot pay. Fix this NOW!',
            ['questions' => [
                'urgent' => new BooleanQuestion('Does this need an immediate response?', 'Explicitly time-sensitive', 'No urgency expressed'),
                'department' => new ChoiceQuestion('Which team should handle this?', ['billing' => 'Payments, invoices, refunds', 'technical' => 'Bugs, outages, integrations']),
                'frustration' => new ScoreQuestion('How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry']),
            ]],
        );

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testItOmitsBooleanCriteriaWhenNoneAreGiven()
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $this->assertSame(
                '{"model":"jev","state":"Buy cheap watches!","questions":{"spam":{"type":"noul","instructions":"Is this spam?"}}}',
                $options['body'],
            );

            return new JsonMockResponse(['answers' => []]);
        });

        $client = new ModelClient($httpClient, 'test-key');
        $client->request(new TypeSafe('jev'), 'Buy cheap watches!', ['questions' => ['spam' => new BooleanQuestion('Is this spam?')]]);

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testItSendsStructuredStateAndKeepsNumericKeysAsObjects()
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $this->assertSame(
                '{"model":"jev","state":{"subject":"Refund","body":"Please refund order 42."},"questions":{"0":{"type":"choice","instructions":"Priority?","criteria":{"0":"Low","1":"High"}}}}',
                $options['body'],
            );

            return new JsonMockResponse(['answers' => []]);
        });

        $client = new ModelClient($httpClient, 'test-key');
        $client->request(
            new TypeSafe('jev'),
            ['subject' => 'Refund', 'body' => 'Please refund order 42.'],
            // PHP turns numeric-string labels into integer keys, which must still be sent as JSON objects
            /* @phpstan-ignore argument.type */
            ['questions' => [new ChoiceQuestion('Priority?', ['Low', 'High'])]],
        );

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testItUsesCustomBaseUrl()
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): JsonMockResponse {
            $this->assertSame('https://typesafe.example.com/v1/systemone', $url);

            return new JsonMockResponse(['answers' => []]);
        });

        $client = new ModelClient($httpClient, 'test-key', 'https://typesafe.example.com/');
        $client->request(new TypeSafe('jev'), 'state', ['questions' => ['spam' => new BooleanQuestion('Is this spam?')]]);

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testItThrowsOnEmptyState()
    {
        $client = new ModelClient(new MockHttpClient(), 'test-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The state to classify must not be empty.');

        $client->request(new TypeSafe('jev'), '', ['questions' => ['spam' => new BooleanQuestion('Is this spam?')]]);
    }

    public function testItThrowsWithoutQuestions()
    {
        $client = new ModelClient(new MockHttpClient(), 'test-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The "questions" option must be a non-empty map of question names to questions.');

        $client->request(new TypeSafe('jev'), 'state');
    }

    public function testItThrowsOnInvalidQuestion()
    {
        $client = new ModelClient(new MockHttpClient(), 'test-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The question "spam" must be an instance of "%s", "string" given.', QuestionInterface::class));

        $client->request(new TypeSafe('jev'), 'state', ['questions' => ['spam' => 'Is this spam?']]);
    }

    public function testItThrowsOnUnsupportedQuestionType()
    {
        $client = new ModelClient(new MockHttpClient(), 'test-key');
        $question = new class implements QuestionInterface {
            public function getInstructions(): string
            {
                return 'Summarize this.';
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not supported by TypeSafe.');

        $client->request(new TypeSafe('jev'), 'state', ['questions' => ['summary' => $question]]);
    }
}
