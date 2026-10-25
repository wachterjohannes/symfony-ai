<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Agent\Exception;

/**
 * An exception carrying a machine readable code, exposed by {@see \Symfony\AI\Agent\Execution\ExecutionInterface::getErrorCode()}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface ErrorCodeAwareInterface
{
    /**
     * @return non-empty-string
     */
    public function getErrorCode(): string;
}
