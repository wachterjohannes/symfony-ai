<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Tests\MessageStore;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Execution\ExecutionState;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Agent\MessageStore\InMemoryMessageStore;
use Symfony\AI\Agent\MessageStore\MessageStoreInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Serializer\MessageBagNormalizer;
use Symfony\AI\Platform\Serializer\MessageNormalizer;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\TimeBasedUidInterface;

final class SteppedStatefulAgentTest extends TestCase
{
    public function testDeferredToolCallsLeaveTheRunWaitingInTheStoreAndAWorkerCompletesIt()
    {
        $toolCall = new ToolCall('call_1', 'clock');
        $store = new InMemoryMessageStore();
        $agent = new Agent($this->platform(), 'gpt-4', toolbox: $this->toolbox($toolCall, $executions), messageStore: $store);
        $messages = new MessageBag(Message::ofUser('What time is it?'));

        $first = $agent->call($messages, ['defer_tool_calls' => true]);
        $first->getResult();

        $stored = $store->load($messages->getId());
        $this->assertSame([UserMessage::class, AssistantMessage::class], array_map(get_class(...), $stored->getMessages()));
        $this->assertSame(ExecutionStatus::WaitingForInput, ExecutionState::statusOf($stored));
        $this->assertSame(ExecutionStatus::WaitingForInput, $first->getStatus());
        $this->assertSame(0, $executions);

        $second = $agent->call($store->load($messages->getId())->with(Message::ofToolCall($toolCall, '12:00')), ['defer_tool_calls' => true]);

        $this->assertSame('It is noon.', $second->getContent());
        $this->assertSame(ExecutionStatus::Completed, $second->getStatus());

        $stored = $store->load($messages->getId());
        $this->assertSame(
            [UserMessage::class, AssistantMessage::class, ToolCallMessage::class, AssistantMessage::class],
            array_map(get_class(...), $stored->getMessages()),
        );
        $this->assertSame(ExecutionStatus::Completed, ExecutionState::statusOf($stored));
    }

    public function testMaxRoundsSavesAfterTheRoundAndTheNextCallContinues()
    {
        $toolCall = new ToolCall('call_1', 'clock');
        $store = new InMemoryMessageStore();
        $agent = new Agent($this->platform(), 'gpt-4', toolbox: $this->toolbox($toolCall, $executions), messageStore: $store);
        $messages = new MessageBag(Message::ofUser('What time is it?'));

        $first = $agent->call($messages, ['max_rounds' => 1]);
        $first->getResult();

        $stored = $store->load($messages->getId());
        $this->assertSame(1, $executions);
        $this->assertSame(
            [UserMessage::class, AssistantMessage::class, ToolCallMessage::class],
            array_map(get_class(...), $stored->getMessages()),
        );
        $this->assertSame(ExecutionStatus::Pending, ExecutionState::statusOf($stored));

        $second = $agent->call($stored, ['max_rounds' => 1]);

        $this->assertSame('It is noon.', $second->getContent());
        $this->assertSame(1, $executions);
        $this->assertSame(ExecutionStatus::Completed, ExecutionState::statusOf($store->load($messages->getId())));
    }

    public function testTheExecutionIdAndTheRoundSurviveTheStoreSerialization()
    {
        $toolCall = new ToolCall('call_1', 'clock');
        $store = $this->serializingStore();
        $agent = new Agent($this->platform(), 'gpt-4', toolbox: $this->toolbox($toolCall, $executions), messageStore: $store);
        $messages = new MessageBag(Message::ofUser('What time is it?'));

        $first = $agent->call($messages, ['max_rounds' => 1]);
        $first->getResult();

        $stored = $store->load($messages->getId())->getMessages();
        $this->assertSame($first->getId()->toRfc4122(), $stored[1]->getMetadata()->get('execution_id'));
        $this->assertSame(1, $stored[1]->getMetadata()->get('round'));

        $second = $agent->call($store->load($messages->getId()));
        $second->getResult();

        $stored = $store->load($messages->getId())->getMessages();
        $this->assertSame($first->getId()->toRfc4122(), $stored[1]->getMetadata()->get('execution_id'));
        $this->assertSame($second->getId()->toRfc4122(), $stored[3]->getMetadata()->get('execution_id'));
        $this->assertSame(2, $stored[3]->getMetadata()->get('round'));
    }

    private function platform(): PlatformInterface
    {
        return MockPlatformFactory::createPlatform(static function (mixed $model, array $input): ResultInterface {
            $last = $input['messages'][array_key_last($input['messages'])] ?? null;

            return \is_array($last) && 'tool' === ($last['role'] ?? null)
                ? new TextResult('It is noon.')
                : new ToolCallResult([new ToolCall('call_1', 'clock')]);
        });
    }

    /**
     * @param-out int $executions
     */
    private function toolbox(ToolCall $toolCall, ?int &$executions): ToolboxInterface
    {
        $executions = 0;
        $toolbox = $this->createStub(ToolboxInterface::class);
        $toolbox->method('getTools')->willReturn([]);
        $toolbox->method('execute')->willReturnCallback(static function () use ($toolCall, &$executions): ToolResult {
            ++$executions;

            return new ToolResult($toolCall, '12:00');
        });

        return $toolbox;
    }

    private function serializingStore(): MessageStoreInterface
    {
        $serializer = new Serializer([new MessageBagNormalizer(), new MessageNormalizer()], [new JsonEncoder()]);

        return new class($serializer) implements MessageStoreInterface {
            /**
             * @var array<string, string>
             */
            private array $json = [];

            public function __construct(private readonly Serializer $serializer)
            {
            }

            public function load(AbstractUid&TimeBasedUidInterface $id): MessageBag
            {
                $key = $id->toRfc4122();
                if (!isset($this->json[$key])) {
                    return (new MessageBag())->withId($id);
                }

                return $this->serializer->deserialize($this->json[$key], MessageBag::class, 'json');
            }

            public function save(MessageBag $messages): void
            {
                $this->json[$messages->getId()->toRfc4122()] = $this->serializer->serialize($messages, 'json');
            }
        };
    }
}
