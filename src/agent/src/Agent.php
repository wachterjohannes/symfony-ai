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

use Symfony\AI\Agent\Exception\InvalidArgumentException;
use Symfony\AI\Agent\Exception\RuntimeException;
use Symfony\AI\Agent\Execution\Cancellation;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Run\Run;
use Symfony\AI\Agent\Execution\Run\RunState;
use Symfony\AI\Agent\Execution\Run\RunStoreInterface;
use Symfony\AI\Agent\Execution\Runner;
use Symfony\AI\Agent\Execution\Update\Progress;
use Symfony\AI\Agent\Execution\Update\Result as ResultUpdate;
use Symfony\AI\Agent\Toolbox\SequentialToolExecutor;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolExecutorInterface;
use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class Agent implements AgentInterface
{
    private readonly Runner $runner;

    /**
     * @param InputProcessorInterface[]  $inputProcessors
     * @param OutputProcessorInterface[] $outputProcessors
     * @param non-empty-string           $model
     * @param bool                       $excludeToolMessages keeps the messages appended during tool calling out of the caller's message bag
     * @param bool                       $includeSources      exposes the sources collected during tool calling as `sources` result metadata
     * @param ?RunStoreInterface         $runStore            keeps the runs started with {@see start()} between their rounds
     */
    public function __construct(
        PlatformInterface $platform,
        private readonly string $model,
        private readonly iterable $inputProcessors = [],
        private readonly iterable $outputProcessors = [],
        private readonly string $name = 'agent',
        ?ToolboxInterface $toolbox = null,
        ?ToolExecutorInterface $toolExecutor = null,
        ?int $maxToolCalls = 50,
        bool $excludeToolMessages = false,
        bool $includeSources = false,
        ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?RunStoreInterface $runStore = null,
    ) {
        if (null === $toolExecutor && $toolbox instanceof ToolboxInterface) {
            $toolExecutor = new SequentialToolExecutor($toolbox);
        }

        $this->runner = new Runner(
            $platform,
            $toolbox,
            $toolExecutor,
            $maxToolCalls,
            $excludeToolMessages,
            $includeSources,
            $eventDispatcher,
        );
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Starts the agent and returns a lazy {@see Execution} that is also the result it produces.
     *
     * Read it eagerly with `->getContent()`/`->getResult()`, iterate it to observe every model request, tool call
     * and streamed delta as an update, or register callbacks via `->onProgress(...)`. With the "stream" option
     * set, `->getContent()` yields the answer's deltas.
     *
     * @param array<string, mixed> $options
     *
     * @throws InvalidArgumentException When the platform returns a client error (4xx) indicating invalid request parameters
     * @throws RuntimeException         When the platform returns a server error (5xx) or network failure occurs
     * @throws ExceptionInterface       When the platform converter throws an exception
     */
    public function call(string|MessageBag|UserMessage $input, array $options = []): Execution
    {
        $cancellation = new Cancellation();
        $factory = function () use ($input, $options, $cancellation): \Generator {
            [$model, $messages, $processedOptions] = $this->processInput($input, $options);

            $result = null;
            foreach ($this->runner->run($model, $messages, $processedOptions, $cancellation) as $update) {
                if ($update instanceof ResultUpdate) {
                    $result = $update->getResult();

                    continue;
                }

                yield $update;
            }

            if ($cancellation->isRequested()) {
                return;
            }

            \assert($result instanceof ResultInterface);

            $result = $this->processOutput($model, $result, $messages, $processedOptions);

            yield new ResultUpdate($result);
        };

        return new Execution($factory, true === ($options['stream'] ?? false), $cancellation);
    }

    /**
     * Starts a run that advances one round per {@see resume()} call, so the rounds can happen in a worker
     * while the caller polls the run from the store.
     *
     * Nothing is sent to the model yet, the first round is executed by the first {@see resume()}.
     *
     * @param array<string, mixed> $options
     */
    public function start(string|MessageBag|UserMessage $input, array $options = []): Run
    {
        [$model, $messages, $processedOptions] = $this->processInput($input, $options);

        $run = new Run(bin2hex(random_bytes(16)), new RunState($model, $messages, $processedOptions));
        $this->getRunStore()->save($run);

        return $run;
    }

    /**
     * Executes the next round of a run and saves the run, after every update of the round, so a poller sees
     * the tool calls and streamed deltas while the round is still going on.
     *
     * A finished run is returned as it is. A failure of the round fails the run instead of being thrown,
     * its message is on the run.
     *
     * @param non-empty-string $id
     *
     * @throws Exception\RunNotFoundException
     * @throws Exception\RunConflictException when another process advanced the run at the same time
     */
    public function resume(string $id): Run
    {
        $store = $this->getRunStore();
        $run = $store->get($id);

        if ($run->isFinished()) {
            return $run;
        }

        $state = $run->getState();

        try {
            $round = $this->runner->step($state);
            foreach ($round as $update) {
                if ($update instanceof Progress) {
                    $run->record($update);
                    $store->save($run);
                }
            }

            $result = $round->getReturn();
            if (null !== $result) {
                $run->complete($this->processOutput($state->getModel(), $result, $state->getMessages(), $state->getOptions()));
            }
        } catch (Exception\RunConflictException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $run->fail($exception);
        }

        $store->save($run);

        return $run;
    }

    /**
     * @return array{non-empty-string, MessageBag, array<string, mixed>}
     */
    private function processInput(string|MessageBag|UserMessage $input, array $options): array
    {
        $request = new Input($this->getModel(), InputNormalizer::toMessageBag($input), $options);
        foreach ($this->inputProcessors as $inputProcessor) {
            if (!$inputProcessor instanceof InputProcessorInterface) {
                throw new InvalidArgumentException(\sprintf('Input processor "%s" must implement "%s".', $inputProcessor::class, InputProcessorInterface::class));
            }

            if ($inputProcessor instanceof AgentAwareInterface) {
                $inputProcessor->setAgent($this);
            }

            $inputProcessor->processInput($request);
        }

        return [$request->getModel(), $request->getMessageBag(), $request->getOptions()];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function processOutput(string $model, ResultInterface $result, MessageBag $messages, array $options): ResultInterface
    {
        $output = new Output($model, $result, $messages, $options);
        foreach ($this->outputProcessors as $outputProcessor) {
            if (!$outputProcessor instanceof OutputProcessorInterface) {
                throw new InvalidArgumentException(\sprintf('Output processor "%s" must implement "%s".', $outputProcessor::class, OutputProcessorInterface::class));
            }

            if ($outputProcessor instanceof AgentAwareInterface) {
                $outputProcessor->setAgent($this);
            }

            $outputProcessor->processOutput($output);
        }

        return $output->getResult();
    }

    private function getRunStore(): RunStoreInterface
    {
        return $this->runStore ?? throw new RuntimeException('Pass a run store to the agent to start and resume runs.');
    }
}
