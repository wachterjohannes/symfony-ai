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
class XmlContainerPathIsNotConfiguredException extends InvalidArgumentException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'Failed to configure path to Symfony container. You passed an empty string',
            0,
            $previous
        );
    }
}
