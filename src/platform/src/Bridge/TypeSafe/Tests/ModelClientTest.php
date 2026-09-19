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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\TypeSafe\ModelClient;
use Symfony\AI\Platform\Bridge\TypeSafe\TypeSafe;
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

        $this->assertTrue($client->supports(new TypeSafe('jev-latest')));
    }

    public function testItDoesNotSupportOtherModels()
    {
        $client = new ModelClient(new MockHttpClient(), 'test-key');

        $this->assertFalse($client->supports(new Model('jev-latest')));
    }

    public function testItSendsExpectedRequest()
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.typesafe.ai/v1/systemone', $url);
            $this->assertContains('Authorization: Bearer test-key', $options['headers']);

            $this->assertSame([
                'model' => 'jev-latest',
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
                ],
            ], json_decode($options['body'], true));

            return new JsonMockResponse(['answers' => []]);
        });

        $client = new ModelClient($httpClient, 'test-key');
        $client->request(new TypeSafe('jev-latest'), [
            'state' => 'Our checkout is down since this morning and customers cannot pay. Fix this NOW!',
            'questions' => [
                'urgent' => (object) [
                    'type' => 'noul',
                    'instructions' => 'Does this need an immediate response?',
                    'criteria' => (object) ['true' => 'Explicitly time-sensitive', 'false' => 'No urgency expressed'],
                ],
                'department' => (object) [
                    'type' => 'choice',
                    'instructions' => 'Which team should handle this?',
                    'criteria' => (object) ['billing' => 'Payments, invoices, refunds', 'technical' => 'Bugs, outages, integrations'],
                ],
            ],
        ]);

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testItSendsStructuredStateAndKeepsNumericQuestionNamesAsObject()
    {
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): JsonMockResponse {
            $this->assertSame(
                '{"model":"jev-latest","state":{"subject":"Refund","body":"Please refund order 42."},"questions":{"0":{"type":"noul","instructions":"Is this urgent?"}}}',
                $options['body'],
            );

            return new JsonMockResponse(['answers' => []]);
        });

        $client = new ModelClient($httpClient, 'test-key');
        $client->request(new TypeSafe('jev-latest'), [
            'state' => ['subject' => 'Refund', 'body' => 'Please refund order 42.'],
            // PHP turns numeric-string names into integer keys, which must still be sent as a JSON object
            'questions' => [['type' => 'noul', 'instructions' => 'Is this urgent?']],
        ]);

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    public function testItUsesCustomBaseUrl()
    {
        $httpClient = new MockHttpClient(function (string $method, string $url): JsonMockResponse {
            $this->assertSame('https://typesafe.example.com/v1/systemone', $url);

            return new JsonMockResponse(['answers' => []]);
        });

        $client = new ModelClient($httpClient, 'test-key', 'https://typesafe.example.com/');
        $client->request(new TypeSafe('jev-latest'), [
            'state' => 'state',
            'questions' => ['spam' => ['type' => 'noul', 'instructions' => 'Is this spam?']],
        ]);

        $this->assertSame(1, $httpClient->getRequestsCount());
    }

    /**
     * @param array<string, mixed>|string $payload
     */
    #[DataProvider('invalidPayloadProvider')]
    public function testItThrowsOnInvalidPayload(array|string $payload)
    {
        $client = new ModelClient(new MockHttpClient(), 'test-key');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The TypeSafe payload must be an array with a "state" and a "questions" key.');

        $client->request(new TypeSafe('jev-latest'), $payload);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|string}>
     */
    public static function invalidPayloadProvider(): iterable
    {
        yield 'string' => ['Is this spam?'];
        yield 'without state' => [['questions' => ['spam' => ['type' => 'noul', 'instructions' => 'Is this spam?']]]];
        yield 'without questions' => [['state' => 'Buy cheap watches!']];
    }
}
