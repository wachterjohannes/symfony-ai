<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests\Classification;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Classification\BooleanQuestion;
use Symfony\AI\Platform\Classification\ChoiceQuestion;
use Symfony\AI\Platform\Classification\ScoreQuestion;
use Symfony\AI\Platform\Exception\InvalidArgumentException;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class QuestionTest extends TestCase
{
    public function testBooleanQuestion()
    {
        $question = new BooleanQuestion('Does this need an immediate response?', 'Explicitly time-sensitive', 'No urgency expressed');

        $this->assertSame('Does this need an immediate response?', $question->getInstructions());
        $this->assertSame('Explicitly time-sensitive', $question->getTrueCriterion());
        $this->assertSame('No urgency expressed', $question->getFalseCriterion());
    }

    public function testBooleanQuestionCriteriaAreOptional()
    {
        $question = new BooleanQuestion('Is this spam?');

        $this->assertNull($question->getTrueCriterion());
        $this->assertNull($question->getFalseCriterion());
    }

    public function testChoiceQuestion()
    {
        $options = ['billing' => 'Payments, invoices, refunds', 'technical' => 'Bugs, outages, integrations'];
        $question = new ChoiceQuestion('Which team should handle this?', $options);

        $this->assertSame('Which team should handle this?', $question->getInstructions());
        $this->assertSame($options, $question->getOptions());
    }

    public function testChoiceQuestionRequiresAtLeastTwoOptions()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A choice question requires at least two options.');

        new ChoiceQuestion('Which team should handle this?', ['billing' => 'Payments']);
    }

    public function testScoreQuestion()
    {
        $question = new ScoreQuestion('How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry']);

        $this->assertSame('How frustrated is the customer?', $question->getInstructions());
        $this->assertSame(['Calm', 'Frustrated', 'Very angry'], $question->getLevels());
    }

    public function testScoreQuestionRequiresAtLeastTwoLevels()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A score question requires at least two levels.');

        new ScoreQuestion('How frustrated is the customer?', ['Calm']);
    }
}
