<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\GraphProvider\Exception;

use Symfony\AI\Mate\Exception\RuntimeException;

/**
 * Thrown when a {@see \Symfony\AI\Mate\Bridge\Symfony\GraphProvider\GraphProviderInterface}
 * cannot populate the graph due to a non-recoverable error (corrupt cache file, malformed
 * data source, etc.). Providers should prefer no-op behaviour for "data not available yet"
 * conditions and reserve this exception for actual integrity violations.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
class GraphProviderException extends RuntimeException
{
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
