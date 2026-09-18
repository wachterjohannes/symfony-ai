<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Classification;

/**
 * Typed answer to a {@see QuestionInterface}.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface AnswerInterface
{
    /**
     * Calibrated confidence in the answer, or null when the provider cannot measure it.
     */
    public function getConfidence(): ?float;
}
