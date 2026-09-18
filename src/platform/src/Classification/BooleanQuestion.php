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
 * Yes/no question, optionally describing what counts as true and false.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class BooleanQuestion implements QuestionInterface
{
    public function __construct(
        private readonly string $instructions,
        private readonly ?string $trueCriterion = null,
        private readonly ?string $falseCriterion = null,
    ) {
    }

    public function getInstructions(): string
    {
        return $this->instructions;
    }

    public function getTrueCriterion(): ?string
    {
        return $this->trueCriterion;
    }

    public function getFalseCriterion(): ?string
    {
        return $this->falseCriterion;
    }
}
