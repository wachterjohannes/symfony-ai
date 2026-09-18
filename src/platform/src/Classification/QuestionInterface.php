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
 * A typed question a classification model answers about a given state.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
interface QuestionInterface
{
    public function getInstructions(): string;
}
