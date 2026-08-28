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
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\SearchableToolbox;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\AI\Platform\Bridge\OpenAi\Factory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;

require_once dirname(__DIR__).'/bootstrap.php';

#[AsTool('convert_currency', 'Convert an amount from one currency into another')]
final class CurrencyConverter
{
    public function __invoke(float $amount, string $from, string $to): string
    {
        return sprintf('%.2f %s are %.2f %s.', $amount, $from, $amount * 1.08, $to);
    }
}

#[AsTool('get_invoice', 'Fetch an invoice by its number')]
final class InvoiceFetcher
{
    public function __invoke(string $number): string
    {
        return sprintf('Invoice %s over 1.337,00 EUR, issued to ACME Inc.', $number);
    }
}

#[AsTool('release_train', 'Trigger the release train of a deployment pipeline')]
final class ReleaseTrain
{
    public function __invoke(string $pipeline): string
    {
        return sprintf('Release train of pipeline "%s" started.', $pipeline);
    }
}

$platform = Factory::createPlatform(env('OPENAI_API_KEY'), http_client());

$toolbox = new Toolbox([new CurrencyConverter(), new InvoiceFetcher(), new ReleaseTrain()], logger: logger());

// only the tool search is exposed to the model, the three tools above have to be found first
$toolbox = new SearchableToolbox($toolbox);

$agent = new Agent($platform, 'gpt-5-mini', toolbox: $toolbox);

$messages = new MessageBag(Message::ofUser('How much is the invoice 2024-042 in US dollars?'));
$result = $agent->call($messages);

echo $result->getContent().\PHP_EOL;
