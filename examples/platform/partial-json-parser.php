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
use Symfony\AI\Platform\StructuredOutput\Serializer;
use Symfony\AI\Platform\StructuredOutput\Streaming\PartialJsonParser;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * Target DTOs. All properties default to a "missing" value so the serializer
 * can denormalize partial snapshots without tripping over required fields.
 */
final class Author
{
    public function __construct(
        public ?string $name = null,
        public ?string $country = null,
    ) {
    }
}

final class Book
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public ?string $title = null,
        public ?Author $author = null,
        public array $tags = [],
        public ?bool $published = null,
    ) {
    }
}

/**
 * Accumulates text deltas from a streamed JSON response, feeds each
 * intermediate buffer into PartialJsonParser, and denormalizes the
 * recovered array into a typed instance using the platform's
 * StructuredOutput serializer (= same normalizers as the non-streaming
 * structured output path).
 *
 * @template T of object
 */
final class PartialObjectStreamListener extends AbstractStreamListener
{
    private string $buffer = '';
    private ?object $lastSnapshot = null;

    /**
     * @param class-string<T>            $outputType
     * @param callable(T $partial): void $onPartial
     */
    public function __construct(
        private readonly DenormalizerInterface $denormalizer,
        private readonly string $outputType,
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

        if (!is_array($partial)) {
            return;
        }

        try {
            /** @var T $object */
            $object = $this->denormalizer->denormalize($partial, $this->outputType);
        } catch (SerializerExceptionInterface) {
            // Partial structure does not yet satisfy the target type — wait for more deltas.
            return;
        }

        if ($object == $this->lastSnapshot) {
            return;
        }

        $this->lastSnapshot = $object;
        ($this->onPartial)($object);
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
$stream->addListener(new PartialObjectStreamListener(
    new Serializer(),
    Book::class,
    static function (Book $book) use (&$step): void {
        ++$step;
        echo sprintf("Snapshot %d:\n", $step);
        dump($book);
        echo "\n";
    },
));

// Consume the stream so listeners receive deltas; the listener is the one
// reacting to each chunk — we don't need the raw text here.
foreach ($deferred->asTextStream() as $_) {
}
