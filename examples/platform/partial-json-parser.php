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
use Symfony\AI\Platform\Result\DeferredResult;
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
 * Wraps a deferred streaming result and yields typed partial objects every time
 * the recovered structure changes. Each iteration of the returned generator
 * produces a denser snapshot of the same logical object, populated from the
 * JSON fragments emitted by the model so far.
 *
 * @template T of object
 *
 * @param class-string<T> $outputType
 *
 * @return Generator<T>
 */
function streamPartialObjects(
    DeferredResult $deferred,
    DenormalizerInterface $denormalizer,
    string $outputType,
): Generator {
    $buffer = '';
    $last = null;

    foreach ($deferred->asTextStream() as $delta) {
        $buffer .= $delta->getText();

        $partial = PartialJsonParser::parse($buffer);

        if (!is_array($partial)) {
            continue;
        }

        try {
            /** @var T $object */
            $object = $denormalizer->denormalize($partial, $outputType);
        } catch (SerializerExceptionInterface) {
            // Partial structure does not yet satisfy the target type — wait for more deltas.
            continue;
        }

        if ($object == $last) {
            continue;
        }

        $last = $object;

        yield $object;
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

$step = 0;
foreach (streamPartialObjects($deferred, new Serializer(), Book::class) as $book) {
    ++$step;
    echo sprintf("Snapshot %d:\n", $step);
    dump($book);
    echo "\n";
}
