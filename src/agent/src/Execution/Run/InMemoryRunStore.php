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
 * Keeps serialized runs in memory, so it behaves like a store that crosses a process boundary.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class InMemoryRunStore implements RunStoreInterface
{
    /**
     * @var array<string, array{int, string}>
     */
    private array $runs = [];

    public function get(string $id): Run
    {
        if (!isset($this->runs[$id])) {
            throw new RunNotFoundException($id);
        }

        [, $serialized] = $this->runs[$id];

        $run = unserialize($serialized);
        \assert($run instanceof Run);

        return $run;
    }

    public function save(Run $run): void
    {
        $stored = $this->runs[$run->getId()][0] ?? 0;
        if ($stored !== $run->getVersion()) {
            throw new RunConflictException($run->getId());
        }

        $run->withVersion($stored + 1);
        $this->runs[$run->getId()] = [$stored + 1, serialize($run)];
    }
}
