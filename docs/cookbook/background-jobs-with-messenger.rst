.. card:
    title: Background Jobs with Messenger
    description: Start a long-running provider job in a request and finish it in a Messenger worker.
    icon: clock
    components: Platform

Background Jobs with Messenger
==============================

MiniMax video generation routinely runs for a minute or two, far too long to keep a web request
waiting. Such an invocation returns no payload but a
:class:`Symfony\\AI\\Platform\\Job\\JobHandle` — a reference holding no client and no connection, so
you can store it, hand it on, and come back to the job from another process entirely.

In this guide you start a video job in a request, return immediately, and let a Messenger worker
finish it, using a delayed message as the polling interval. The job API itself is documented in the
:doc:`Platform component </components/platform>` reference.

Prerequisites
-------------

* Symfony AI Platform component and AI Bundle
* Symfony Messenger with a transport that supports delays (Doctrine, Redis, Amazon SQS, AMQP)
* A MiniMax API key

Step 1: Install Packages
------------------------

Install the bundle and Messenger::

    composer require symfony/ai-bundle symfony/messenger

Configure the MiniMax platform:

.. code-block:: yaml

    # config/packages/ai.yaml
    ai:
        platform:
            minimax:
                api_key: '%env(MINIMAX_API_KEY)%'

Step 2: Define the Message
--------------------------

The message carries the handle, where the finished video should end up, and a deadline. The handle
is a plain value object, so Messenger's default serializer stores it as-is::

    namespace App\Message;

    use Symfony\AI\Platform\Job\JobHandle;

    final readonly class FinishVideoJob
    {
        public function __construct(
            public JobHandle $handle,
            public string $targetPath,
            public \DateTimeImmutable $deadline,
        ) {
        }
    }

The deadline stops the message from being redelivered forever, and you do not have to guess it:
``getMaxDuration()`` tells you how many seconds the bridge considers reasonable for that kind of job.

.. note::

    If the transport is configured with the Symfony Serializer rather than the default PHP
    serializer, convert the handle explicitly with ``JobHandle::toArray()`` and
    ``JobHandle::fromArray()``.

Step 3: Start the Job in the Request
------------------------------------

The controller invokes the model, reads the handle, dispatches the message and returns. No waiting
happens here — the response goes out while the provider is still rendering::

    namespace App\Controller;

    use App\Message\FinishVideoJob;
    use Symfony\AI\Platform\Message\Content\Text;
    use Symfony\AI\Platform\PlatformInterface;
    use Symfony\Component\Clock\ClockInterface;
    use Symfony\Component\DependencyInjection\Attribute\Autowire;
    use Symfony\Component\HttpFoundation\JsonResponse;
    use Symfony\Component\HttpFoundation\Request;
    use Symfony\Component\HttpFoundation\Response;
    use Symfony\Component\Messenger\MessageBusInterface;
    use Symfony\Component\Routing\Attribute\Route;

    final class GenerateVideoController
    {
        public function __construct(
            #[Autowire(service: 'ai.platform.minimax')]
            private PlatformInterface $platform,
            private MessageBusInterface $bus,
            private ClockInterface $clock,
            #[Autowire('%kernel.project_dir%/var/videos')]
            private string $videoDirectory,
        ) {
        }

        #[Route('/videos', methods: ['POST'])]
        public function __invoke(Request $request): Response
        {
            $handle = $this->platform->invoke('MiniMax-Hailuo-02', new Text($request->request->getString('prompt')), [
                'duration' => 6,
            ])->asJob();

            $this->bus->dispatch(new FinishVideoJob(
                $handle,
                \sprintf('%s/%s.mp4', $this->videoDirectory, $handle->getId()),
                $this->clock->now()->modify(\sprintf('+%d seconds', $handle->getMaxDuration() ?? 600)),
            ));

            return new JsonResponse(['job' => $handle->getId()], Response::HTTP_ACCEPTED);
        }
    }

The message is a work item, not a record. If the video belongs to a user, store the handle in a
database row as well — it is ``JsonSerializable``, so you can put it straight into a JSON column.

Step 4: Finish the Job in the Handler
-------------------------------------

The handler asks the provider once — :class:`Symfony\\AI\\Platform\\Job\\JobClientInterface` performs
exactly one request per call and never sleeps — and then decides what to do with the answer::

    namespace App\MessageHandler;

    use App\Message\FinishVideoJob;
    use Symfony\AI\Platform\Job\JobPlatformInterface;
    use Symfony\AI\Platform\Job\JobStateCase;
    use Symfony\AI\Platform\Result\BinaryResult;
    use Symfony\Component\Clock\ClockInterface;
    use Symfony\Component\DependencyInjection\Attribute\Autowire;
    use Symfony\Component\Messenger\Attribute\AsMessageHandler;
    use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;
    use Symfony\Component\Messenger\MessageBusInterface;
    use Symfony\Component\Messenger\Stamp\DelayStamp;

    #[AsMessageHandler]
    final class FinishVideoJobHandler
    {
        public function __construct(
            #[Autowire(service: 'ai.platform.minimax')]
            private JobPlatformInterface $platform,
            private MessageBusInterface $bus,
            private ClockInterface $clock,
        ) {
        }

        public function __invoke(FinishVideoJob $message): void
        {
            $jobClient = $this->platform->getJobClient($message->handle);
            $status = $jobClient->getStatus($message->handle);

            if ($status->is(JobStateCase::SUCCEEDED)) {
                $result = $jobClient->getResult($message->handle);
                \assert($result instanceof BinaryResult);

                $result->asFile($message->targetPath);

                return;
            }

            if ($status->isTerminal()) {
                throw new UnrecoverableMessageHandlingException(\sprintf(
                    'The video job "%s" ended as "%s". %s',
                    $message->handle->getId(),
                    $status->getRaw(),
                    $status->getError() ?? '',
                ));
            }

            if ($this->clock->now() > $message->deadline) {
                throw new UnrecoverableMessageHandlingException(\sprintf(
                    'The video job "%s" did not finish before its deadline.',
                    $message->handle->getId(),
                ));
            }

            $this->bus->dispatch($message, [new DelayStamp(10_000)]);
        }
    }

Two details are worth pointing out. A job that failed or expired at the provider will not improve on
a retry, so :class:`Symfony\\Component\\Messenger\\Exception\\UnrecoverableMessageHandlingException`
sends it straight to the failure transport — where you still have the handle, and can pick the job
up by hand. And while the job runs, the handler dispatches the message again behind a
:class:`Symfony\\Component\\Messenger\\Stamp\\DelayStamp`: that delay is your polling interval, and
unlike a ``sleep()`` loop it survives a worker restart or a deploy.

Anything else you throw — a network blip while asking for the status — stays a normal failure and
goes through the configured retry strategy.

Step 5: Route the Message
-------------------------

Delays need a transport that can hold a message. Doctrine, Redis, SQS and AMQP all can; the ``sync``
transport cannot, and would turn the redispatch into unbounded recursion:

.. code-block:: yaml

    # config/packages/messenger.yaml
    framework:
        messenger:
            transports:
                async: '%env(MESSENGER_TRANSPORT_DSN)%'
            routing:
                App\Message\FinishVideoJob: async

Step 6: Run the Worker
----------------------

.. code-block:: terminal

    $ php bin/console messenger:consume async -vv

Post a prompt to ``/videos`` and the response returns straight away with a job id. The worker logs a
handful of redeliveries roughly ten seconds apart, and once the provider reports success the file
appears under ``var/videos/``. Stopping the worker mid-job costs nothing: the message comes back on
the next start.

Ten seconds suits video; asynchronous speech finishes in seconds, so pick a shorter delay there. And
if you can afford to block — in a console command, or a fixture script — skip all of this and use
:class:`Symfony\\AI\\Platform\\Job\\JobRunner`, which does the same polling in-process.

Learn More
----------

* `Asynchronous Video Generation with MiniMax <https://github.com/symfony/ai/blob/main/examples/minimax/text-to-video.php>`_
* `Resuming a MiniMax Video Job <https://github.com/symfony/ai/blob/main/examples/minimax/video-job-resume.php>`_
* :doc:`../components/platform` - Platform component documentation, including the job API
* :doc:`../bundles/ai-bundle` - AI Bundle configuration reference
