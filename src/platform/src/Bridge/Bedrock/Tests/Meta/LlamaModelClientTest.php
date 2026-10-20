<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\Bedrock\Tests\Meta;

use AsyncAws\BedrockRuntime\BedrockRuntimeClient;
use AsyncAws\BedrockRuntime\Input\InvokeModelRequest;
use AsyncAws\BedrockRuntime\Result\InvokeModelResponse;
use AsyncAws\Core\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Bedrock\Meta\LlamaModelClient;
use Symfony\AI\Platform\Bridge\Meta\Llama;

final class LlamaModelClientTest extends TestCase
{
    public function testCanonicalTemperatureIsSentInTheBody()
    {
        $bedrockClient = $this->getMockBuilder(BedrockRuntimeClient::class)
            ->setConstructorArgs([
                Configuration::create([Configuration::OPTION_REGION => Configuration::DEFAULT_REGION]),
            ])
            ->onlyMethods(['invokeModel'])
            ->getMock();

        $bedrockClient->expects($this->once())
            ->method('invokeModel')
            ->with($this->callback(function ($arg) {
                $this->assertInstanceOf(InvokeModelRequest::class, $arg);

                $body = json_decode($arg->getBody(), true);
                $this->assertSame('Hello', $body['prompt']);
                $this->assertSame(0.35, $body['temperature']);

                return true;
            }))
            ->willReturn($this->createMock(InvokeModelResponse::class));

        $client = new LlamaModelClient($bedrockClient);
        $client->request(new Llama('llama-3.3-70b-instruct'), ['prompt' => 'Hello'], ['temperature' => 0.35]);
    }
}
