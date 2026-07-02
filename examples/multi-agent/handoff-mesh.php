<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\InputProcessor\SystemPromptInputProcessor;
use Symfony\AI\Agent\MultiAgent\Handoff;
use Symfony\AI\Agent\MultiAgent\Handoff\Transfer;
use Symfony\AI\Agent\MultiAgent\MultiAgent;
use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;

require_once dirname(__DIR__).'/bootstrap.php';

/**
 * A specialist agent that, once selected, hands the conversation off to another agent by returning a Transfer
 * as its result. In a real application an agent typically produces a Transfer from a tool call or via structured
 * output (response_format: Transfer::class); this in-file agent keeps the example deterministic.
 */
final class SupportDispatcher implements AgentInterface
{
    public function getName(): string
    {
        return 'dispatcher';
    }

    public function call(MessageBag $messages, array $options = []): ResultInterface
    {
        $question = strtolower($messages->getUserMessage()?->asText() ?? '');

        $isBilling = str_contains($question, 'charge')
            || str_contains($question, 'refund')
            || str_contains($question, 'invoice')
            || str_contains($question, 'payment')
            || str_contains($question, 'subscription');

        $target = $isBilling ? 'billing' : 'technical';

        return new ObjectResult(new Transfer($target, sprintf('Dispatcher routed the request to the "%s" specialist.', $target)));
    }
}

$dispatcher = new EventDispatcher();
$dispatcher->addSubscriber(new PlatformSubscriber());
$platform = Factory::createPlatform(env('OPENAI_API_KEY'), http_client(), eventDispatcher: $dispatcher);

// The orchestrator makes the initial routing decision. Here it always routes to the dispatcher, which then hands
// off to the right specialist - demonstrating an agent-to-agent handoff carrying the full conversation.
$orchestrator = new Agent(
    $platform,
    'gpt-5-mini',
    [new SystemPromptInputProcessor('You are the support front desk. Always route the request to the "dispatcher" agent.')],
);

// Specialists that produce the final answer.
$billing = new Agent(
    $platform,
    'gpt-4o-mini?max_output_tokens=150', // set max_output_tokens here to be faster and cheaper
    [new SystemPromptInputProcessor('You are a billing specialist. Help users with charges, refunds, invoices and payments.')],
    name: 'billing',
);

$technical = new Agent(
    $platform,
    'gpt-4o-mini?max_output_tokens=150',
    [new SystemPromptInputProcessor('You are a technical support specialist. Help users resolve bugs, errors and broken features.')],
    name: 'technical',
);

$fallback = new Agent(
    $platform,
    'gpt-5-mini',
    [new SystemPromptInputProcessor('You are a helpful general assistant for anything outside billing or technical support.')],
    name: 'fallback',
);

// The dispatcher is registered as a handoff target too, so the orchestrator can select it. `maxHops` caps the
// number of hand-offs to guard against routing loops.
$multiAgent = new MultiAgent(
    orchestrator: $orchestrator,
    handoffs: [
        new Handoff(to: new SupportDispatcher(), when: ['support', 'help', 'account', 'issue', 'question']),
        new Handoff(to: $billing, when: ['charge', 'refund', 'invoice', 'payment', 'subscription']),
        new Handoff(to: $technical, when: ['bug', 'error', 'broken', 'technical', 'export']),
    ],
    fallback: $fallback,
    logger: logger(),
    maxHops: 5,
);

foreach ([
    'I was charged twice for my subscription this month, can you help?',
    'The CSV export button in the dashboard throws a 500 error.',
] as $question) {
    echo "=== Support request ===\n";
    echo "Question: $question\n\n";
    $result = $multiAgent->call(new MessageBag(Message::ofUser($question)));
    echo 'Answer: '.substr($result->getContent(), 0, 300).'...'.\PHP_EOL.\PHP_EOL;
}
