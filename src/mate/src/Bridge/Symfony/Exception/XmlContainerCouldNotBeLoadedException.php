<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Bridge\Symfony\Exception;

use Symfony\AI\Mate\Exception\InvalidArgumentException;

/**
 * @internal
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class XmlContainerCouldNotBeLoadedException extends InvalidArgumentException
{
    public function __construct(string $path, bool $cannotBeParsed = false, ?\Throwable $previous = null)
    {
        $message = $cannotBeParsed
            ? \sprintf('Container "%s" cannot be parsed', $path)
            : \sprintf('Container "%s" does not exist', $path);

        parent::__construct($message, 0, $previous);
    }
}
