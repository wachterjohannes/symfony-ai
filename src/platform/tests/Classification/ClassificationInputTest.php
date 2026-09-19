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
use Symfony\AI\Platform\Classification\ClassificationInput;
use Symfony\AI\Platform\Classification\QuestionInterface;
use Symfony\AI\Platform\Exception\InvalidArgumentException;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ClassificationInputTest extends TestCase
{
    public function testItExposesStateAndQuestions()
    {
        $question = new BooleanQuestion('Is this spam?');
        $input = new ClassificationInput('Buy cheap watches!', ['spam' => $question]);

        $this->assertSame('Buy cheap watches!', $input->getState());
        $this->assertSame(['spam' => $question], $input->getQuestions());
    }

    public function testItAcceptsStructuredState()
    {
        $input = new ClassificationInput(['subject' => 'Refund'], ['spam' => new BooleanQuestion('Is this spam?')]);

        $this->assertSame(['subject' => 'Refund'], $input->getState());
    }

    public function testItThrowsOnEmptyState()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The state to classify must not be empty.');

        new ClassificationInput('', ['spam' => new BooleanQuestion('Is this spam?')]);
    }

    public function testItThrowsWithoutQuestions()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A classification requires at least one question.');

        new ClassificationInput('Buy cheap watches!', []);
    }

    public function testItThrowsOnInvalidQuestion()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('The question "spam" must be an instance of "%s", "string" given.', QuestionInterface::class));

        /* @phpstan-ignore argument.type */
        new ClassificationInput('Buy cheap watches!', ['spam' => 'Is this spam?']);
    }
}
