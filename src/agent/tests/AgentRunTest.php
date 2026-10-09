<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Exception\RunConflictException;
use Symfony\AI\Agent\Exception\RunNotFoundException;
use Symfony\AI\Agent\Exception\RuntimeException;
use Symfony\AI\Agent\Execution\Run\InMemoryRunStore;
use Symfony\AI\Agent\Execution\Run\RunStatus;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Output;
use Symfony\AI\Agent\OutputProcessorInterface;
use Symfony\AI\Agent\ResumableAgentInterface;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;

final class AgentRunTest extends TestCase
{
    public function testTheAgentIsResumable()
    {
        $this->assertInstanceOf(ResumableAgentInterface::class, $this->agent(new InMemoryPlatform('Hi'), new InMemoryRunStore()));
    }

    public function testStartDoesNotCallThePlatform()
    {
        $invocations = 0;
        $platform = new InMemoryPlatform(static function () use (&$invocations): string {
            ++$invocations;

            return 'Hi';
        });

        $run = $this->agent($platform, new InMemoryRunStore())->start('Hello');

        $this->assertSame(0, $invocations);
        $this->assertSame(RunStatus::Pending, $run->getStatus());
        $this->assertSame([], $run->getEventsSince());
        $this->assertNull($run->getResult());
    }

    public function testAnAnswerWithoutToolsCompletesTheRunInOneRound()
    {
        $store = new InMemoryRunStore();
        $agent = $this->agent(new InMemoryPlatform('Hi there'), $store);

        $run = $agent->resume($agent->start('Hello')->getId());

        $this->assertSame(RunStatus::Completed, $run->getStatus());
        $this->assertTrue($run->isFinished());
        $this->assertSame('Hi there', $run->getResult()?->getContent());
        $this->assertSame(RunStatus::Completed, $store->get($run->getId())->getStatus());
    }

    public function testEachToolRoundIsOneResumeAndAnotherAgentCanContinue()
    {
        $toolCall = new ToolCall('call-1', 'lookup', ['q' => 'ficus']);
        $store = new InMemoryRunStore();

        $first = $this->agent($this->platformAnsweringAfterATool($toolCall), $store, $this->toolbox($toolCall));
        $runId = $first->start('Find a ficus')->getId();

        $run = $first->resume($runId);
        $this->assertSame(RunStatus::Running, $run->getStatus());
        $this->assertNull($run->getResult());

        // a second agent has nothing in common with the first one but the store, like a worker in another process
        $second = $this->agent($this->platformAnsweringAfterATool($toolCall), $store, $this->toolbox($toolCall));
        $run = $second->resume($runId);

        $this->assertSame(RunStatus::Completed, $run->getStatus());
        $this->assertSame('Found: 3 ficus', $run->getResult()?->getContent());
    }

    public function testEventsCanBePolledBySequence()
    {
        $toolCall = new ToolCall('call-1', 'lookup', []);
        $store = new InMemoryRunStore();
        $agent = $this->agent($this->platformAnsweringAfterATool($toolCall), $store, $this->toolbox($toolCall));
        $runId = $agent->start('Find a ficus')->getId();

        $agent->resume($runId);
        $seen = $store->get($runId)->getEventsSince();
        $this->assertSame([Progress::STAGE_MODEL_REQUEST, Progress::STAGE_TOOL_CALL], array_map(static fn ($event) => $event->getStage(), $seen));
        $this->assertSame([1, 2], array_map(static fn ($event) => $event->getSequence(), $seen));

        $agent->resume($runId);
        $new = $store->get($runId)->getEventsSince(2);
        $this->assertSame([Progress::STAGE_MODEL_REQUEST], array_map(static fn ($event) => $event->getStage(), $new));
        $this->assertSame(3, $new[0]->getSequence());
    }

    public function testTheOutputProcessorsRunWhenTheRunCompletes()
    {
        $processor = new class implements OutputProcessorInterface {
            public function processOutput(Output $output): void
            {
                $output->setResult(new TextResult(strtoupper((string) $output->getResult()->getContent())));
            }
        };
        $store = new InMemoryRunStore();
        $agent = new Agent(new InMemoryPlatform('hi'), 'gpt-4o', outputProcessors: [$processor], runStore: $store);

        $run = $agent->resume($agent->start('Hello')->getId());

        $this->assertSame('HI', $run->getResult()?->getContent());
    }

    public function testAFailedRoundFailsTheRun()
    {
        $toolCall = new ToolCall('call-1', 'lookup', []);
        $platform = new InMemoryPlatform(static fn (): ResultInterface => new ToolCallResult([$toolCall]));
        $store = new InMemoryRunStore();
        $agent = $this->agent($platform, $store, $this->toolbox($toolCall), maxToolCalls: 1);
        $runId = $agent->start('Loop')->getId();

        $this->assertSame(RunStatus::Running, $agent->resume($runId)->getStatus());

        $run = $agent->resume($runId);

        $this->assertSame(RunStatus::Failed, $run->getStatus());
        $this->assertSame('Maximum number of tool calling iterations (1) exceeded.', $run->getError());
        $this->assertSame(RunStatus::Failed, $store->get($runId)->getStatus());
    }

    public function testAFinishedRunIsNotAdvancedAgain()
    {
        $invocations = 0;
        $platform = new InMemoryPlatform(static function () use (&$invocations): string {
            ++$invocations;

            return 'Hi';
        });
        $agent = $this->agent($platform, new InMemoryRunStore());
        $runId = $agent->start('Hello')->getId();

        $agent->resume($runId);
        $run = $agent->resume($runId);

        $this->assertSame(1, $invocations);
        $this->assertSame(RunStatus::Completed, $run->getStatus());
    }

    public function testResumingAnUnknownRunThrows()
    {
        $this->expectException(RunNotFoundException::class);

        $this->agent(new InMemoryPlatform('Hi'), new InMemoryRunStore())->resume('unknown');
    }

    public function testTheStoreRefusesAStaleRun()
    {
        $store = new InMemoryRunStore();
        $agent = $this->agent(new InMemoryPlatform('Hi'), $store);
        $runId = $agent->start('Hello')->getId();

        $stale = $store->get($runId);
        $agent->resume($runId);

        $this->expectException(RunConflictException::class);

        $store->save($stale);
    }

    public function testRunsNeedAStore()
    {
        $this->expectException(RuntimeException::class);

        (new Agent(new InMemoryPlatform('Hi'), 'gpt-4o'))->start('Hello');
    }

    public function testTheToolMessagesStayInTheRunNotInTheCallersMessageBag()
    {
        $toolCall = new ToolCall('call-1', 'lookup', []);
        $store = new InMemoryRunStore();
        $agent = $this->agent($this->platformAnsweringAfterATool($toolCall), $store, $this->toolbox($toolCall), excludeToolMessages: true);
        $messages = new MessageBag(Message::ofUser('Find a ficus'));

        $runId = $agent->start($messages)->getId();
        $agent->resume($runId);

        $this->assertCount(1, $messages);
        $this->assertCount(3, $store->get($runId)->getState()->getMessages());
    }

    private function agent(InMemoryPlatform $platform, InMemoryRunStore $store, ?ToolboxInterface $toolbox = null, ?int $maxToolCalls = 50, bool $excludeToolMessages = false): Agent
    {
        return new Agent($platform, 'gpt-4o', toolbox: $toolbox, maxToolCalls: $maxToolCalls, excludeToolMessages: $excludeToolMessages, runStore: $store);
    }

    private function platformAnsweringAfterATool(ToolCall $toolCall): InMemoryPlatform
    {
        return new InMemoryPlatform(static function (mixed $model, mixed $input) use ($toolCall): ResultInterface {
            $messages = $input instanceof MessageBag ? $input->getMessages() : [];

            return \count($messages) > 1 ? new TextResult('Found: 3 ficus') : new ToolCallResult([$toolCall]);
        });
    }

    private function toolbox(ToolCall $toolCall): ToolboxInterface
    {
        $toolbox = $this->createStub(ToolboxInterface::class);
        $toolbox->method('getTools')->willReturn([]);
        $toolbox->method('execute')->willReturn(new ToolResult($toolCall, '3 ficus'));

        return $toolbox;
    }
}
