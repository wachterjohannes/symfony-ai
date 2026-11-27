<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * Canned OpenAI Responses API answer so the demo can run without an API key (DEMO_MOCK_HTTP=1).
 */
final class MockResponses
{
    public static function client(): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options): JsonMockResponse {
            $body = json_decode($options['body'] ?? '{}', true);
            $prompt = json_encode($body['input'] ?? null);

            return new JsonMockResponse([
                'id' => 'resp_mock',
                'object' => 'response',
                'status' => 'completed',
                'model' => $body['model'] ?? 'mock',
                'output' => [[
                    'type' => 'message',
                    'id' => 'msg_mock',
                    'status' => 'completed',
                    'role' => 'assistant',
                    'content' => [['type' => 'output_text', 'text' => \sprintf('[mocked %s %s] You asked: %s', $method, parse_url($url, \PHP_URL_HOST), $prompt), 'annotations' => []]],
                ]],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
            ]);
        });
    }
}
