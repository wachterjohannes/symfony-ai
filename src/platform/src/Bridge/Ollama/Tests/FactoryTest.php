<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Ollama\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Ollama\Factory;
use Symfony\AI\Platform\Bridge\TypeSafe\Answer\Answers;
use Symfony\AI\Platform\Bridge\TypeSafe\Evaluation;
use Symfony\AI\Platform\Bridge\TypeSafe\Question\ChoiceQuestion;
use Symfony\AI\Platform\Bridge\TypeSafe\Question\NoulQuestion;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class FactoryTest extends TestCase
{
    public function testItEvaluatesQuestionsWithADecisionModel()
    {
        $httpClient = new MockHttpClient([
            function (string $method, string $url, array $options): JsonMockResponse {
                $this->assertSame('POST', $method);
                $this->assertSame('http://127.0.0.1:11434/api/show', $url);

                return new JsonMockResponse(['capabilities' => ['decision', 'tools', 'thinking', 'completion']]);
            },
            function (string $method, string $url, array $options): JsonMockResponse {
                $this->assertSame('POST', $method);
                $this->assertSame('http://127.0.0.1:11434/v1/systemone', $url);

                $body = json_decode($options['body'], true);
                $this->assertSame('nimble', $body['model']);
                $this->assertSame('Our checkout has returned 500 errors since 9am.', $body['state']);
                $this->assertSame('noul', $body['questions']['is_urgent']['type']);
                $this->assertSame('choice', $body['questions']['label']['type']);

                return new JsonMockResponse([
                    'model' => 'nimble',
                    'answers' => [
                        'is_urgent' => ['type' => 'noul', 'noul' => 0.97],
                        'label' => ['type' => 'choice', 'choice' => 'bug', 'probabilities' => ['billing' => 0.02, 'bug' => 0.98], 'confidence' => 0.89],
                    ],
                    'usage' => ['input_tokens' => 174, 'output_tokens' => 1],
                ]);
            },
        ]);

        $result = Factory::createPlatform('http://127.0.0.1:11434', httpClient: $httpClient)->invoke('nimble', new Evaluation('Our checkout has returned 500 errors since 9am.', [
            'is_urgent' => new NoulQuestion('Is this urgent?'),
            'label' => new ChoiceQuestion('Which label fits this ticket?', ['billing' => 'Payments and refunds', 'bug' => 'Software errors']),
        ]));

        $answers = $result->asObject();
        $this->assertInstanceOf(Answers::class, $answers);
        $this->assertSame(0.97, $answers->getNoul('is_urgent')->getProbability());
        $this->assertSame('bug', $answers->getChoice('label')->getChoice());

        $tokenUsage = $result->getMetadata()->get('token_usage');
        $this->assertInstanceOf(TokenUsage::class, $tokenUsage);
        $this->assertSame(174, $tokenUsage->getPromptTokens());
        $this->assertSame(1, $tokenUsage->getCompletionTokens());
    }
}
