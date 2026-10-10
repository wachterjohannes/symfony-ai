<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent;

use Symfony\AI\Agent\Exception\RunConflictException;
use Symfony\AI\Agent\Exception\RunNotFoundException;
use Symfony\AI\Agent\Execution\Run\Run;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;

/**
 * An agent that can also run in rounds, so the rounds are executed by different processes while a client
 * polls the {@see Run} from its store.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface ResumableAgentInterface extends AgentInterface
{
    /**
     * Starts a run that advances one round per {@see resume()} call.
     *
     * Nothing is sent to the model yet, the first round is executed by the first {@see resume()}.
     *
     * @param array<string, mixed> $options
     */
    public function start(string|MessageBag|UserMessage $input, array $options = []): Run;

    /**
     * Executes the next round of a run and saves the run, after every update of the round, so a poller sees
     * the tool calls and streamed deltas of a round while it is still going on.
     *
     * A finished run is returned as it is. A failure of the round fails the run instead of being thrown,
     * its message is on the run.
     *
     * @param non-empty-string $id
     *
     * @throws RunNotFoundException
     * @throws RunConflictException when another process advanced the run at the same time
     */
    public function resume(string $id): Run;
}
