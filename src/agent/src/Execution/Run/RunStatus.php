<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Execution\Run;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
enum RunStatus: string
{
    /**
     * Started, no round was executed yet.
     */
    case Pending = 'pending';

    /**
     * At least one round was executed, further rounds are needed.
     */
    case Running = 'running';

    /**
     * Paused until someone answers, see {@see Run::getInputRequest()}.
     */
    case WaitingForInput = 'waiting_for_input';

    case Completed = 'completed';

    case Failed = 'failed';

    public function isFinished(): bool
    {
        return self::Completed === $this || self::Failed === $this;
    }
}
