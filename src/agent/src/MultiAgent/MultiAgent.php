<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\MultiAgent;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Exception\ExceptionInterface;
use Symfony\AI\Agent\Exception\InvalidArgumentException;
use Symfony\AI\Agent\Exception\RuntimeException;
use Symfony\AI\Agent\MultiAgent\Handoff\Decision;
use Symfony\AI\Agent\MultiAgent\Handoff\Transfer;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ResultInterface;

/**
 * A multi-agent system that coordinates multiple specialized agents.
 *
 * This agent acts as a central orchestrator, delegating tasks to specialized agents based on handoff rules. The
 * orchestrator selects the initial specialist; from there, a specialist may hand the conversation over to another
 * agent by returning a {@see Transfer} as its result, forming a handoff mesh. The full conversation is carried
 * across hops, control can be handed back to the orchestrator, and a hop budget prevents runaway routing.
 *
 * @author Oskar Stark <oskarstark@googlemail.com>
 */
final class MultiAgent implements AgentInterface
{
    /**
     * @param AgentInterface   $orchestrator Agent responsible for analyzing requests and selecting appropriate handoffs
     * @param Handoff[]        $handoffs     Handoff definitions for agent routing
     * @param AgentInterface   $fallback     Fallback agent when no handoff conditions match
     * @param non-empty-string $name         Name of the multi-agent
     * @param LoggerInterface  $logger       Logger for debugging handoff decisions
     * @param int              $maxHops      Maximum number of agent hops before the routing loop is stopped (must be >= 1)
     */
    public function __construct(
        private AgentInterface $orchestrator,
        private array $handoffs,
        private AgentInterface $fallback,
        private string $name = 'multi-agent',
        private LoggerInterface $logger = new NullLogger(),
        private int $maxHops = 10,
    ) {
        if ([] === $handoffs) {
            throw new InvalidArgumentException('MultiAgent requires at least 1 handoff.');
        }

        if ($maxHops < 1) {
            throw new InvalidArgumentException('MultiAgent requires a positive "maxHops" value.');
        }
    }

    /**
     * @return non-empty-string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @throws ExceptionInterface When the agent encounters an error during orchestration or handoffs
     */
    public function call(MessageBag $messages, array $options = []): ResultInterface
    {
        $conversation = $messages->withoutSystemMessage();

        if (null === $conversation->getUserMessage()) {
            throw new RuntimeException('No user message found in conversation.');
        }

        $decision = $this->makeDecision($conversation, $options);
        if (null === $decision) {
            $this->logger->debug('MultiAgent: Failed to get decision, falling back to orchestrator');

            return $this->orchestrator->call($messages, $options);
        }

        if (!$decision->hasAgent()) {
            $this->logger->debug('MultiAgent: Using fallback agent', ['reason' => 'no_agent_selected']);

            return $this->fallback->call($messages, $options);
        }

        $targetAgent = $this->findAgent($decision->getAgentName());
        if (null === $targetAgent) {
            $this->logger->debug('MultiAgent: Target agent not found, using fallback agent', [
                'requested_agent' => $decision->getAgentName(),
                'reason' => 'agent_not_found',
            ]);

            return $this->fallback->call($messages, $options);
        }

        for ($hop = 1; $hop <= $this->maxHops; ++$hop) {
            $this->logger->debug('MultiAgent: Delegating to agent', [
                'agent_name' => $targetAgent->getName(),
                'hop' => $hop,
            ]);

            // Pass the full conversation so the specialist keeps the prior context, not just the last message.
            $result = $targetAgent->call($conversation, $options);

            $transfer = $this->extractTransfer($result);
            if (null === $transfer) {
                return $result;
            }

            $this->logger->debug('MultiAgent: Agent requested handoff', [
                'from' => $targetAgent->getName(),
                'to' => $transfer->getAgentName(),
            ]);

            // Preserve the handoff intent in the running conversation for the next agent.
            $conversation = $conversation->with(Message::ofAssistant(
                $transfer->getReason() ?? \sprintf('Handing off to "%s".', $transfer->getAgentName()),
            ));

            $nextAgent = $this->resolveHandoffTarget($transfer, $conversation, $options);
            if (null === $nextAgent) {
                $this->logger->debug('MultiAgent: Handoff target not found, returning current result', [
                    'requested_agent' => $transfer->getAgentName(),
                ]);

                return $result;
            }

            $targetAgent = $nextAgent;
        }

        $this->logger->debug('MultiAgent: Hop budget exhausted, using fallback agent', [
            'max_hops' => $this->maxHops,
        ]);

        return $this->fallback->call($messages, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function makeDecision(MessageBag $conversation, array $options): ?Decision
    {
        $userMessage = $conversation->getUserMessage();
        $userText = null !== $userMessage ? $userMessage->asText() : '';

        $this->logger->debug('MultiAgent: Processing user message', ['user_text' => $userText]);
        $this->logger->debug('MultiAgent: Available agents for routing', ['agents' => array_map(static fn (Handoff $handoff): array => [
            'to' => $handoff->getTo()->getName(),
            'when' => $handoff->getWhen(),
        ], $this->handoffs)]);

        $decision = $this->orchestrator->call(new MessageBag(Message::ofUser($this->buildAgentSelectionPrompt($userText))), array_merge($options, [
            'response_format' => Decision::class,
        ]))->getContent();

        if (!$decision instanceof Decision) {
            return null;
        }

        $this->logger->debug('MultiAgent: Agent selection completed', [
            'selected_agent' => $decision->getAgentName(),
            'reasoning' => $decision->getReasoning(),
        ]);

        return $decision;
    }

    /**
     * Resolves the agent a transfer points to, allowing a hand-back to the orchestrator for re-selection.
     *
     * @param array<string, mixed> $options
     */
    private function resolveHandoffTarget(Transfer $transfer, MessageBag $conversation, array $options): ?AgentInterface
    {
        $target = $this->findAgent($transfer->getAgentName());
        if (null !== $target) {
            return $target;
        }

        if ($transfer->getAgentName() === $this->orchestrator->getName()) {
            $decision = $this->makeDecision($conversation, $options);
            if (null !== $decision && $decision->hasAgent()) {
                return $this->findAgent($decision->getAgentName());
            }
        }

        return null;
    }

    private function findAgent(string $name): ?AgentInterface
    {
        foreach ($this->handoffs as $handoff) {
            if ($handoff->getTo()->getName() === $name) {
                return $handoff->getTo();
            }
        }

        return null;
    }

    private function extractTransfer(ResultInterface $result): ?Transfer
    {
        $content = $result->getContent();

        return $content instanceof Transfer ? $content : null;
    }

    private function buildAgentSelectionPrompt(string $userQuestion): string
    {
        $agentDescriptions = [];
        $agentNames = [];

        foreach ($this->handoffs as $handoff) {
            $triggers = implode(', ', $handoff->getWhen());
            $agentName = $handoff->getTo()->getName();
            $agentDescriptions[] = "- {$agentName}: {$triggers}";
            $agentNames[] = $agentName;
        }

        $agentDescriptions[] = "- {$this->fallback->getName()}: fallback agent for general/unmatched queries";
        $agentNames[] = $this->fallback->getName();

        $agentList = implode("\n", $agentDescriptions);
        $validAgents = implode('", "', $agentNames);

        return <<<PROMPT
            You are an intelligent agent orchestrator. Based on the user's question, determine which specialized agent should handle the request.

            User question: "{$userQuestion}"

            Available agents and their capabilities:
            {$agentList}

            Analyze the user's question and select the most appropriate agent to handle this request.
            Return an empty string ("") for agentName if no specific agent matches the request criteria.

            Available agent names: {$validAgents}

            Provide your selection and explain your reasoning.
            PROMPT;
    }
}
