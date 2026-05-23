<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\AI\Platform\StructuredOutput\Streaming\PartialJsonParser;

require_once dirname(__DIR__).'/bootstrap.php';

// Simulates the progressive deltas a chat completion would emit when producing
// structured JSON output. Each chunk is appended to a running buffer and we try
// to render the largest valid structure we can recover so far.
$chunks = [
    '{"title": "Symfony AI", ',
    '"tags": ["php", "llm",',
    ' "agents"], "author": {"name": "Fa',
    'bien", "twitter": "@fabpot"',
    '}, "released": tru',
    'e}',
];

$buffer = '';

foreach ($chunks as $index => $chunk) {
    $buffer .= $chunk;

    $partial = PartialJsonParser::parse($buffer, $error);

    echo sprintf("Chunk %d: %s\n", $index + 1, $chunk);
    echo 'Buffer: '.$buffer."\n";

    if (null !== $partial) {
        echo 'Parsed: '.json_encode($partial, \JSON_PRETTY_PRINT)."\n\n";
    } else {
        echo 'Unrecoverable: '.$error."\n\n";
    }
}
