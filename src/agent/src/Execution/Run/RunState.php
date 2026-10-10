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

use Symfony\AI\Agent\Toolbox\Source\SourceCollection;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Metadata\Metadata;

/**
 * Everything the agent needs to carry on after a round: the conversation including the tool messages
 * appended so far, the options of the call and what was aggregated over the finished rounds.
 *
 * It is the only state of a run, so persisting it between two rounds is enough to resume in another process.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 *
 * @internal
 */
final class RunState
{
    private int $rounds = 0;

    private SourceCollection $sources;

    private Metadata $metadata;

    /**
     * @param non-empty-string     $model
     * @param array<string, mixed> $options the options as given to the platform, before the tools are exposed
     */
    public function __construct(
        private readonly string $model,
        private readonly MessageBag $messages,
        private readonly array $options = [],
    ) {
        $this->sources = new SourceCollection();
        $this->metadata = new Metadata();
    }

    /**
     * @return non-empty-string
     */
    public function getModel(): string
    {
        return $this->model;
    }

    public function getMessages(): MessageBag
    {
        return $this->messages;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * The number of rounds in which the model asked for tools.
     */
    public function getToolRounds(): int
    {
        return $this->rounds;
    }

    public function countToolRound(): int
    {
        return ++$this->rounds;
    }

    public function getSources(): SourceCollection
    {
        return $this->sources;
    }

    public function mergeSources(SourceCollection $sources): void
    {
        $this->sources = $this->sources->merge($sources);
    }

    public function getMetadata(): Metadata
    {
        return $this->metadata;
    }
}
