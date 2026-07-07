<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Tests\MultiAgent;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\InvalidArgumentException;
use Symfony\AI\Agent\MultiAgent\Handoff;
use Symfony\AI\Agent\MultiAgent\Handoff\Decision;
use Symfony\AI\Agent\MultiAgent\Handoff\Transfer;
use Symfony\AI\Agent\MultiAgent\MultiAgent;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\TextResult;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class MultiAgentMeshTest extends TestCase
{
    public function testConstructorRejectsNonPositiveMaxHops()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('MultiAgent requires a positive "maxHops" value.');

        new MultiAgent(
            $this->agent('orchestrator'),
            [new Handoff($this->agent('technical'), ['technical'])],
            $this->agent('fallback'),
            'multi-agent',
            new \Psr\Log\NullLogger(),
            0,
        );
    }

    public function testDelegatesFullConversationToSelectedAgent()
    {
        $orchestrator = $this->agent('orchestrator', $this->resultWith(new Decision('technical', 'reason')));

        $received = null;
        $technical = $this->createMock(AgentInterface::class);
        $technical->method('getName')->willReturn('technical');
        $technical->method('call')->willReturnCallback(static function (MessageBag $messages) use (&$received): ResultInterface {
            $received = $messages;

            return new TextResult('answer');
        });

        $multiAgent = new MultiAgent($orchestrator, [new Handoff($technical, ['technical'])], $this->agent('fallback'));

        $conversation = new MessageBag(
            Message::ofUser('first question'),
            Message::ofAssistant('first answer'),
            Message::ofUser('second question'),
        );

        $multiAgent->call($conversation);

        $this->assertInstanceOf(MessageBag::class, $received);
        $this->assertCount(3, $received->getMessages());
    }

    public function testFollowsHandoffWhenAgentReturnsTransfer()
    {
        $orchestrator = $this->agent('orchestrator', $this->resultWith(new Decision('billing', 'billing issue')));

        $billing = $this->agent('billing', $this->resultWith(new Transfer('technical', 'needs the logs')));
        $expected = new TextResult('Fixed the export.');
        $technical = $this->agent('technical', $expected);

        $multiAgent = new MultiAgent($orchestrator, [
            new Handoff($billing, ['billing']),
            new Handoff($technical, ['technical']),
        ], $this->agent('fallback'));

        $result = $multiAgent->call(new MessageBag(Message::ofUser('I was double-charged and my export is broken')));

        $this->assertSame($expected, $result);
    }

    public function testHopBudgetStopsRunawayHandoffs()
    {
        $orchestrator = $this->agent('orchestrator', $this->resultWith(new Decision('alpha', 'start')));

        $alpha = $this->createMock(AgentInterface::class);
        $alpha->method('getName')->willReturn('alpha');
        $alpha->expects($this->once())->method('call')->willReturn($this->resultWith(new Transfer('beta')));

        $beta = $this->createMock(AgentInterface::class);
        $beta->method('getName')->willReturn('beta');
        $beta->expects($this->once())->method('call')->willReturn($this->resultWith(new Transfer('alpha')));

        $fallbackResult = new TextResult('handled by fallback');
        $fallback = $this->agent('fallback', $fallbackResult);

        $multiAgent = new MultiAgent($orchestrator, [
            new Handoff($alpha, ['alpha']),
            new Handoff($beta, ['beta']),
        ], $fallback, 'multi-agent', new \Psr\Log\NullLogger(), 2);

        $result = $multiAgent->call(new MessageBag(Message::ofUser('ping pong')));

        $this->assertSame($fallbackResult, $result);
    }

    public function testHandsBackToOrchestratorForReSelection()
    {
        $orchestrator = $this->createMock(AgentInterface::class);
        $orchestrator->method('getName')->willReturn('orchestrator');
        $orchestrator->method('call')->willReturnOnConsecutiveCalls(
            $this->resultWith(new Decision('frontline', 'first pick')),
            $this->resultWith(new Decision('technical', 're-selected after handback')),
        );

        $frontline = $this->agent('frontline', $this->resultWith(new Transfer('orchestrator', 'not my area')));
        $expected = new TextResult('Resolved by technical.');
        $technical = $this->agent('technical', $expected);

        $multiAgent = new MultiAgent($orchestrator, [
            new Handoff($frontline, ['frontline']),
            new Handoff($technical, ['technical']),
        ], $this->agent('fallback'));

        $result = $multiAgent->call(new MessageBag(Message::ofUser('help')));

        $this->assertSame($expected, $result);
    }

    public function testReturnsCurrentResultWhenHandoffTargetIsUnknown()
    {
        $orchestrator = $this->agent('orchestrator', $this->resultWith(new Decision('solo', 'only option')));

        $soloResult = $this->resultWith(new Transfer('ghost'));
        $solo = $this->agent('solo', $soloResult);

        $multiAgent = new MultiAgent($orchestrator, [new Handoff($solo, ['solo'])], $this->agent('fallback'));

        $result = $multiAgent->call(new MessageBag(Message::ofUser('go')));

        $this->assertSame($soloResult, $result);
    }

    private function agent(string $name, ?ResultInterface $result = null): AgentInterface
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->method('getName')->willReturn($name);
        $agent->method('call')->willReturn($result ?? new TextResult(\sprintf('%s response', $name)));

        return $agent;
    }

    private function resultWith(object $content): ResultInterface
    {
        $result = $this->createMock(ResultInterface::class);
        $result->method('getContent')->willReturn($content);

        return $result;
    }
}
