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
use Symfony\AI\Platform\Classification\BooleanAnswer;
use Symfony\AI\Platform\Classification\ChoiceAnswer;
use Symfony\AI\Platform\Classification\ScoreAnswer;
use Symfony\AI\Platform\Exception\InvalidArgumentException;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class AnswerTest extends TestCase
{
    public function testBooleanAnswer()
    {
        $answer = new BooleanAnswer(0.98, 0.91);

        $this->assertSame(0.98, $answer->getProbability());
        $this->assertTrue($answer->getValue());
        $this->assertSame(0.91, $answer->getConfidence());
    }

    public function testBooleanAnswerBelowHalfIsFalse()
    {
        $answer = new BooleanAnswer(0.49);

        $this->assertSame(0.49, $answer->getProbability());
        $this->assertFalse($answer->getValue());
        $this->assertNull($answer->getConfidence());
    }

    public function testBooleanAnswerRejectsProbabilityOutsideZeroToOne()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The probability must be between 0 and 1, "1.5" given.');

        new BooleanAnswer(1.5);
    }

    public function testChoiceAnswer()
    {
        $answer = new ChoiceAnswer('technical', ['billing' => 0.13, 'technical' => 0.87], 0.82);

        $this->assertSame('technical', $answer->getChoice());
        $this->assertSame(['billing' => 0.13, 'technical' => 0.87], $answer->getProbabilities());
        $this->assertSame(0.82, $answer->getConfidence());
    }

    public function testScoreAnswer()
    {
        $answer = new ScoreAnswer(1.24, [0 => 0.12, 1 => 0.52, 2 => 0.36], [0 => 'Calm', 1 => 'Frustrated', 2 => 'Very angry']);

        $this->assertSame(1.24, $answer->getScore());
        $this->assertSame([0 => 0.12, 1 => 0.52, 2 => 0.36], $answer->getProbabilities());
        $this->assertSame([0 => 'Calm', 1 => 'Frustrated', 2 => 'Very angry'], $answer->getLegend());
        $this->assertSame('Frustrated', $answer->getLevel());
        $this->assertNull($answer->getConfidence());
    }

    public function testScoreAnswerLevelIsNullWithoutLegend()
    {
        $this->assertNull((new ScoreAnswer(1.24))->getLevel());
    }
}
