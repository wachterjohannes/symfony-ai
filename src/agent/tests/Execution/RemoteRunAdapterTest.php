<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Tests\Execution;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\ErrorCodeAwareInterface;
use Symfony\AI\Agent\Exception\RuntimeException;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionEvent;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\ExecutionState;
use Symfony\AI\Agent\Execution\ExecutionStatus;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Agent\InputNormalizer;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\ToolCallMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\TimeBasedUidInterface;

/**
 * A remote service owns the history of a run and every process only advances it by one step.
 */
final class RemoteRunAdapterTest extends TestCase
{
    public function testAPollerAdvancesARemoteRunStepByStep()
    {
        $remote = new FakeRemoteHistory();
        $runId = $remote->start('What is the weather?');
        $weather = new WeatherTool();

        $first = (new RemoteRunAdapter($remote, new Toolbox([$weather])))->call((new MessageBag())->withId($runId));

        $firstMessages = $first->getMessages();

        $this->assertSame(ExecutionStatus::WaitingForInput, $first->getStatus());
        $this->assertEquals([new ToolCall('call_1', 'weather', ['city' => 'Berlin'])], $first->getPendingToolCalls());
        $this->assertSame(0, $weather->calls);
        $this->assertTrue($runId->equals($firstMessages->getId()));

        $second = (new RemoteRunAdapter($remote, new Toolbox([$weather])))->call($firstMessages);
        $secondMessages = $second->getMessages();

        $this->assertSame(ExecutionStatus::Completed, $second->getStatus());
        $this->assertSame([], $second->getPendingToolCalls());
        $this->assertSame('It is sunny in Berlin.', $second->getContent());
        $this->assertSame(1, $weather->calls);
        $this->assertTrue($runId->equals($secondMessages->getId()));
        $this->assertFalse($first->getId()->equals($second->getId()));
    }

    public function testAPollerReadsOnlyTheNewEventsWithItsCursor()
    {
        $remote = new FakeRemoteHistory();
        $runId = $remote->start('What is the weather?');
        $adapter = new RemoteRunAdapter($remote, new Toolbox([new WeatherTool()]));

        $first = $adapter->call((new MessageBag())->withId($runId));
        $this->assertSame([], $first->getEventsSince());

        $messages = $first->getMessages();

        $second = $adapter->call($messages);
        $this->assertSame([1, 2], $this->sequencesOf($second->getEventsSince()));
        $this->assertSame([], $second->getEventsSince(2));

        $messages = $second->getMessages();

        $third = $adapter->call($messages);
        $this->assertSame([3, 4], $this->sequencesOf($third->getEventsSince(2)));
    }

    public function testAFailedRemoteMessageProducesAFailedExecutionWithAnErrorCode()
    {
        $remote = new FakeRemoteHistory();
        $runId = $remote->start('What is the weather?');
        $remote->fail($runId, 'provider_error');

        $execution = (new RemoteRunAdapter($remote, new Toolbox([new WeatherTool()])))->call((new MessageBag())->withId($runId));

        try {
            $execution->getResult();
            $this->fail('The execution should have thrown.');
        } catch (RuntimeException) {
        }

        $this->assertSame(ExecutionStatus::Failed, $execution->getStatus());
        $this->assertSame('provider_error', $execution->getErrorCode());
        $this->assertSame('The remote run failed.', $execution->getError());
    }

    /**
     * @param list<ExecutionEvent> $events
     *
     * @return list<int>
     */
    private function sequencesOf(array $events): array
    {
        return array_map(static fn (ExecutionEvent $event): int => $event->getSequence(), $events);
    }
}

final class RemoteRunException extends RuntimeException implements ErrorCodeAwareInterface
{
    public function __construct(private readonly string $errorCode)
    {
        parent::__construct('The remote run failed.');
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}

final class RemoteRunAdapter implements AgentInterface
{
    public function __construct(
        private readonly FakeRemoteHistory $remote,
        private readonly ToolboxInterface $toolbox,
    ) {
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        $runId = InputNormalizer::toMessageBag($input)->getId();

        $execution = new Execution(function () use ($runId): \Generator {
            if (null !== $code = $this->remote->failureOf($runId)) {
                throw new RemoteRunException($code);
            }

            yield new Progress('remote_step', 'Reading the remote run.');

            foreach (ExecutionState::pendingToolCallsOf($this->remote->history($runId)) as $toolCall) {
                $this->remote->answer($runId, $toolCall, (string) $this->toolbox->execute($toolCall)->getResult());
            }

            if (ExecutionStatus::Pending === ExecutionState::statusOf($this->remote->history($runId))) {
                $this->remote->advance($runId);
            }

            $history = $this->remote->history($runId);
            $pending = ExecutionState::pendingToolCallsOf($history);

            yield new Result([] === $pending ? new TextResult($this->lastText($history)) : new ToolCallResult($pending), $history);
        });

        $step = 0;
        foreach ($this->remote->history($runId)->getMessages() as $message) {
            if ($message instanceof AssistantMessage) {
                $execution->record(new Progress('model_request', 'The remote model answered.'), 2 * $step + 1);
                $execution->record(new Progress('remote_step', 'The remote step finished.'), 2 * $step + 2);
                ++$step;
            }
        }

        return $execution;
    }

    public function getName(): string
    {
        return 'remote';
    }

    private function lastText(MessageBag $history): string
    {
        $messages = $history->getMessages();
        $last = $messages[array_key_last($messages)];
        \assert($last instanceof AssistantMessage);

        return $last->asText() ?? '';
    }
}

final class FakeRemoteHistory
{
    /**
     * @var array<string, MessageBag>
     */
    private array $runs = [];

    /**
     * @var array<string, string>
     */
    private array $failures = [];

    public function start(string $question): AbstractUid&TimeBasedUidInterface
    {
        $history = new MessageBag(Message::ofUser($question));
        $this->runs[$history->getId()->toRfc4122()] = $history;

        return $history->getId();
    }

    public function fail(AbstractUid $id, string $code): void
    {
        $this->failures[$id->toRfc4122()] = $code;
    }

    public function failureOf(AbstractUid $id): ?string
    {
        return $this->failures[$id->toRfc4122()] ?? null;
    }

    public function history(AbstractUid $id): MessageBag
    {
        return $this->runs[$id->toRfc4122()];
    }

    public function answer(AbstractUid $id, ToolCall $toolCall, string $result): void
    {
        $this->runs[$id->toRfc4122()] = $this->history($id)->with(Message::ofToolCall($toolCall, $result));
    }

    /**
     * Plays the model: asks for the weather first and answers once the tool answer is in.
     */
    public function advance(AbstractUid $id): void
    {
        $history = $this->history($id);

        $result = $this->hasToolAnswer($history)
            ? new TextResult('It is sunny in Berlin.')
            : new ToolCallResult([new ToolCall('call_1', 'weather', ['city' => 'Berlin'])]);

        $this->runs[$id->toRfc4122()] = $history->with(Message::ofAssistant($result));
    }

    private function hasToolAnswer(MessageBag $history): bool
    {
        foreach ($history->getMessages() as $message) {
            if ($message instanceof ToolCallMessage) {
                return true;
            }
        }

        return false;
    }
}

#[AsTool('weather', 'Gets the weather of a city')]
final class WeatherTool
{
    public int $calls = 0;

    public function __invoke(string $city): string
    {
        ++$this->calls;

        return \sprintf('Sunny in %s', $city);
    }
}
