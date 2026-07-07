<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\MultiAgent\Handoff;

/**
 * A request by a specialist agent to hand the conversation over to another agent.
 *
 * When an agent's result content is a Transfer, {@see \Symfony\AI\Agent\MultiAgent\MultiAgent} routes the running
 * conversation to the named agent (or hands back to the orchestrator) instead of returning to the user. A
 * specialist typically produces one via structured output or a dedicated "transfer" tool.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class Transfer
{
    /**
     * @param string      $agentName Name of the agent to hand the conversation to. Use the orchestrator's name to
     *                               hand control back to the router for re-selection.
     * @param string|null $reason    Optional human-readable reason, preserved in the conversation for the next agent
     */
    public function __construct(
        private readonly string $agentName,
        private readonly ?string $reason = null,
    ) {
    }

    public function getAgentName(): string
    {
        return $this->agentName;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }
}
