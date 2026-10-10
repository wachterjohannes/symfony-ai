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

use Symfony\AI\Agent\Exception\RunConflictException;
use Symfony\AI\Agent\Exception\RunNotFoundException;

/**
 * Keeps the {@see Run}s between two rounds, so any process can pick one up.
 *
 * A run holds messages, tool results and the result of the model, a store has to persist all of them.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface RunStoreInterface
{
    /**
     * @param non-empty-string $id
     *
     * @throws RunNotFoundException
     */
    public function get(string $id): Run;

    /**
     * Saves the run if nobody saved it since it was read, and bumps its version.
     *
     * @throws RunConflictException when another process saved the run in the meantime
     */
    public function save(Run $run): void;
}
