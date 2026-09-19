<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Platform\Bridge\TypeSafe\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\TypeSafe\Contract\ClassificationInputNormalizer;
use Symfony\AI\Platform\Bridge\TypeSafe\TypeSafe;
use Symfony\AI\Platform\Classification\BooleanQuestion;
use Symfony\AI\Platform\Classification\ChoiceQuestion;
use Symfony\AI\Platform\Classification\ClassificationInput;
use Symfony\AI\Platform\Classification\QuestionInterface;
use Symfony\AI\Platform\Classification\ScoreQuestion;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ClassificationInputNormalizerTest extends TestCase
{
    public function testItSupportsClassificationInputForTypeSafeModels()
    {
        $normalizer = new ClassificationInputNormalizer();
        $input = new ClassificationInput('state', ['spam' => new BooleanQuestion('Is this spam?')]);

        $this->assertTrue($normalizer->supportsNormalization($input, context: [Contract::CONTEXT_MODEL => new TypeSafe('jev-latest')]));
        $this->assertFalse($normalizer->supportsNormalization($input, context: [Contract::CONTEXT_MODEL => new Model('jev-latest')]));
        $this->assertFalse($normalizer->supportsNormalization('state', context: [Contract::CONTEXT_MODEL => new TypeSafe('jev-latest')]));
    }

    public function testItNormalizesEveryQuestionType()
    {
        $normalizer = new ClassificationInputNormalizer();

        $normalized = $normalizer->normalize(new ClassificationInput('The checkout is down, fix this NOW!', [
            'urgent' => new BooleanQuestion('Does this need an immediate response?', 'Explicitly time-sensitive', 'No urgency expressed'),
            'department' => new ChoiceQuestion('Which team should handle this?', ['billing' => 'Payments, invoices, refunds', 'technical' => 'Bugs, outages, integrations']),
            'frustration' => new ScoreQuestion('How frustrated is the customer?', ['Calm', 'Frustrated', 'Very angry']),
        ]));

        $this->assertSame('The checkout is down, fix this NOW!', $normalized['state']);
        $this->assertSame(
            '{"urgent":{"type":"noul","instructions":"Does this need an immediate response?","criteria":{"true":"Explicitly time-sensitive","false":"No urgency expressed"}},"department":{"type":"choice","instructions":"Which team should handle this?","criteria":{"billing":"Payments, invoices, refunds","technical":"Bugs, outages, integrations"}},"frustration":{"type":"score","instructions":"How frustrated is the customer?","criteria":["Calm","Frustrated","Very angry"]}}',
            json_encode($normalized['questions']),
        );
    }

    public function testItOmitsBooleanCriteriaWhenNoneAreGiven()
    {
        $normalizer = new ClassificationInputNormalizer();

        $normalized = $normalizer->normalize(new ClassificationInput('Buy cheap watches!', ['spam' => new BooleanQuestion('Is this spam?')]));

        $this->assertSame('{"spam":{"type":"noul","instructions":"Is this spam?"}}', json_encode($normalized['questions']));
    }

    public function testItKeepsNumericKeysAsJsonObjects()
    {
        $normalizer = new ClassificationInputNormalizer();

        // PHP turns numeric-string names and labels into integer keys, which must still be sent as JSON objects
        /* @phpstan-ignore argument.type */
        $normalized = $normalizer->normalize(new ClassificationInput(['subject' => 'Refund'], [new ChoiceQuestion('Priority?', ['Low', 'High'])]));

        $this->assertSame('{"subject":"Refund"}', json_encode($normalized['state']));
        $this->assertSame('{"0":{"type":"choice","instructions":"Priority?","criteria":{"0":"Low","1":"High"}}}', json_encode($normalized['questions']));
    }

    public function testItThrowsOnUnsupportedQuestionType()
    {
        $normalizer = new ClassificationInputNormalizer();
        $question = new class implements QuestionInterface {
            public function getInstructions(): string
            {
                return 'Summarize this.';
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('is not supported by TypeSafe.');

        $normalizer->normalize(new ClassificationInput('state', ['summary' => $question]));
    }
}
