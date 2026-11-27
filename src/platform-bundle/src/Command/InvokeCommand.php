<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\PlatformBundle\Command;

use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Sends a single prompt to a registered platform and prints the answer.
 */
#[AsCommand(name: 'ai:platform:invoke', description: 'Invoke a model of a configured platform with a prompt')]
final class InvokeCommand extends Command
{
    /**
     * @param ServiceProviderInterface<PlatformInterface> $platforms
     */
    public function __construct(
        private readonly ServiceProviderInterface $platforms,
        private readonly ?string $defaultPlatform = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('prompt', InputArgument::REQUIRED, 'The prompt to send')
            ->addOption('platform', 'p', InputOption::VALUE_REQUIRED, 'Name of the platform (as tagged "ai.platform")')
            ->addOption('model', 'm', InputOption::VALUE_REQUIRED, 'Model name', 'gpt-4o-mini')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $available = array_keys($this->platforms->getProvidedServices());
        $name = $input->getOption('platform') ?? $this->defaultPlatform ?? (1 === \count($available) ? $available[0] : null);
        if (null === $name || !$this->platforms->has($name)) {
            $io->error(\sprintf('Pick a platform with --platform, available: "%s".', implode('", "', $available)));

            return Command::INVALID;
        }

        $result = $this->platforms->get($name)->invoke($input->getOption('model'), new MessageBag(Message::ofUser($input->getArgument('prompt'))));
        $io->writeln($result->asText());

        return Command::SUCCESS;
    }
}
