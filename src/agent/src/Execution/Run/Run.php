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

use Symfony\AI\Agent\Exception\LogicException;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Platform\Result\ResultInterface;

/**
 * A persisted agent invocation that advances one round at a time.
 *
 * Start it with {@see \Symfony\AI\Agent\Agent::start()}, advance it with {@see \Symfony\AI\Agent\Agent::resume()}
 * wherever a worker runs, and read it back from the {@see RunStoreInterface} to poll its status, its events
 * and, once finished, its result.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class Run
{
    private RunStatus $status = RunStatus::Pending;

    /**
     * @var list<RunEvent>
     */
    private array $events = [];

    private ?ResultInterface $result = null;

    private ?string $error = null;

    private int $version = 0;

    /**
     * @param non-empty-string $id
     */
    public function __construct(
        private readonly string $id,
        private ?RunState $state,
    ) {
    }

    /**
     * @return non-empty-string
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /**
     * The events with a sequence number greater than the given one, in order. Pass the sequence of the
     * last event already seen, 0 for all of them.
     *
     * @return list<RunEvent>
     */
    public function getEventsSince(int $sequence = 0): array
    {
        return array_values(array_filter($this->events, static fn (RunEvent $event): bool => $event->getSequence() > $sequence));
    }

    /**
     * The result of a completed run, null as long as it is not completed.
     */
    public function getResult(): ?ResultInterface
    {
        return $this->result;
    }

    /**
     * The message of the exception that failed the run, null if it did not fail.
     */
    public function getError(): ?string
    {
        return $this->error;
    }

    /**
     * Changes with every save, the store uses it to refuse a stale write.
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @internal
     */
    public function getState(): RunState
    {
        return $this->state ?? throw new LogicException('A finished run holds no state anymore.');
    }

    /**
     * @internal
     */
    public function record(Progress $progress): void
    {
        $this->status = RunStatus::Running;
        $this->events[] = RunEvent::fromProgress(\count($this->events) + 1, $progress);
    }

    /**
     * @internal
     */
    public function complete(ResultInterface $result): void
    {
        $this->status = RunStatus::Completed;
        $this->result = $result;
        $this->state = null;
    }

    /**
     * @internal
     */
    public function fail(\Throwable $exception): void
    {
        $this->status = RunStatus::Failed;
        $this->error = $exception->getMessage();
        $this->state = null;
    }

    /**
     * @internal
     */
    public function withVersion(int $version): self
    {
        $this->version = $version;

        return $this;
    }
}
