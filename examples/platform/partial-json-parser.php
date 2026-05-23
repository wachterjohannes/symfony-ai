<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\StructuredOutput\Streaming\PartialJsonParser;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * Accumulates text deltas from a streamed JSON response and feeds each
 * intermediate buffer into PartialJsonParser. Whenever the parser recovers
 * a new valid structure, the listener invokes a user-supplied callback
 * with the partial result so consumers (e.g. a chat UI) can render
 * progressively while the model is still emitting tokens.
 */
final class PartialJsonStreamListener extends AbstractStreamListener
{
    private string $buffer = '';
    private mixed $lastSnapshot = null;

    /**
     * @param callable(mixed $partial, string $buffer): void $onPartial
     */
    public function __construct(
        private $onPartial,
    ) {
    }

    public function onDelta(DeltaEvent $event): void
    {
        $delta = $event->getDelta();

        if (!$delta instanceof TextDelta) {
            return;
        }

        $this->buffer .= $delta->getText();

        $partial = PartialJsonParser::parse($this->buffer);

        if (null === $partial || $partial === $this->lastSnapshot) {
            return;
        }

        $this->lastSnapshot = $partial;
        ($this->onPartial)($partial, $this->buffer);
    }
}

$platform = Factory::createPlatform(env('OPENAI_API_KEY'), http_client());

$messages = new MessageBag(
    Message::forSystem(
        'You produce JSON only. Respond with an object describing a fictional book using the keys '.
        '"title" (string), "author" (object with "name" and "country"), "tags" (array of 3 strings) '.
        'and "published" (boolean). Do not wrap the response in markdown.'
    ),
    Message::ofUser('Give me one recommendation in the cyberpunk genre.'),
);

$deferred = $platform->invoke('gpt-5-mini', $messages, [
    'stream' => true,
]);

$stream = $deferred->getResult();
assert($stream instanceof StreamResult);

$step = 0;
$stream->addListener(new PartialJsonStreamListener(static function (mixed $partial) use (&$step): void {
    ++$step;
    echo sprintf("Snapshot %d:\n", $step);
    echo json_encode($partial, \JSON_PRETTY_PRINT)."\n\n";
}));

// Consume the stream so listeners receive deltas; the listener is the one
// reacting to each chunk — we don't need the raw text here.
foreach ($deferred->asTextStream() as $_) {
}
