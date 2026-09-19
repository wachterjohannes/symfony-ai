<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\AI\Platform\Bridge\TypeSafe\Factory;
use Symfony\AI\Platform\Classification\BooleanQuestion;
use Symfony\AI\Platform\Classification\ChoiceQuestion;
use Symfony\AI\Platform\Classification\ClassificationInput;
use Symfony\AI\Platform\Classification\ScoreQuestion;

require_once dirname(__DIR__).'/bootstrap.php';

$platform = Factory::createPlatform(env('TYPESAFE_API_KEY'), http_client());

$ticket = 'Our checkout is down since this morning and customers cannot pay. Fix this NOW!';

$result = $platform->invoke('jev-latest', new ClassificationInput($ticket, [
    'urgent' => new BooleanQuestion(
        'Does this need an immediate response?',
        trueCriterion: 'Explicitly time-sensitive',
        falseCriterion: 'No urgency expressed',
    ),
    'department' => new ChoiceQuestion('Which team should handle this?', [
        'billing' => 'Payments, invoices, refunds',
        'technical' => 'Bugs, outages, integrations',
    ]),
    'frustration' => new ScoreQuestion('How frustrated is the customer?', [
        'Calm', 'Frustrated', 'Very angry',
    ]),
]))->getResult();

$urgent = $result->getAnswer('urgent');
echo sprintf("Urgent: %s (probability %.2f)\n", $urgent->getValue() ? 'yes' : 'no', $urgent->getProbability());

$department = $result->getAnswer('department');
echo sprintf("Department: %s (confidence %.2f)\n", $department->getChoice(), $department->getConfidence());
foreach ($department->getProbabilities() as $option => $probability) {
    echo sprintf("  %-10s %.2f\n", $option, $probability);
}

$frustration = $result->getAnswer('frustration');
echo sprintf("Frustration: %s (score %.2f)\n", $frustration->getLevel(), $frustration->getScore());

print_token_usage($result->getMetadata()->get('token_usage'));
