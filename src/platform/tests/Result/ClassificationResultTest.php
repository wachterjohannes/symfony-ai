<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Tests\Result;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Classification\BooleanAnswer;
use Symfony\AI\Platform\Classification\ChoiceAnswer;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Result\ClassificationResult;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ClassificationResultTest extends TestCase
{
    public function testGetContentReturnsAnswersByQuestionName()
    {
        $urgent = new BooleanAnswer(true);
        $department = new ChoiceAnswer('technical', ['billing' => 0.13, 'technical' => 0.87], 0.82);

        $result = new ClassificationResult(['urgent' => $urgent, 'department' => $department]);

        $this->assertSame(['urgent' => $urgent, 'department' => $department], $result->getContent());
    }

    public function testGetContentWithNoAnswers()
    {
        $this->assertSame([], (new ClassificationResult())->getContent());
    }

    public function testGetAnswer()
    {
        $urgent = new BooleanAnswer(true);

        $this->assertSame($urgent, (new ClassificationResult(['urgent' => $urgent]))->getAnswer('urgent'));
    }

    public function testGetAnswerThrowsForUnknownQuestion()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The classification result does not contain an answer for "unknown".');

        (new ClassificationResult(['urgent' => new BooleanAnswer(true)]))->getAnswer('unknown');
    }
}
