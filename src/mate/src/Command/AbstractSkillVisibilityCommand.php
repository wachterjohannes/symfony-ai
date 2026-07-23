<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Command;

use Symfony\AI\Mate\Discovery\ComposerExtensionDiscovery;
use Symfony\AI\Mate\Service\ExtensionConfigSynchronizer;
use Symfony\AI\Mate\Skill\Model\DiscoveredSkill;
use Symfony\AI\Mate\Skill\SkillDiscovery;
use Symfony\AI\Mate\Skill\SkillInstaller;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shared plumbing for the visibility axis commands (skills:enable / skills:disable).
 *
 * Both are thin wrappers: resolve the installed name to its (owner, original name), flip the
 * "enabled" intent in mate/extensions.php, then re-run the installer to sync the generated
 * folders. They own no reconciliation logic of their own.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
abstract class AbstractSkillVisibilityCommand extends Command
{
    public function __construct(
        private ComposerExtensionDiscovery $extensionDiscovery,
        private ExtensionConfigSynchronizer $extensionConfigSynchronizer,
        private SkillDiscovery $skillDiscovery,
        private SkillInstaller $installer,
    ) {
        parent::__construct(static::getDefaultName());
    }

    abstract public static function getDefaultName(): string;

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Installed skill name (e.g. mate-system-information)');
    }

    abstract protected function targetEnabled(): bool;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $installedName = $input->getArgument('name');
        \assert(\is_string($installedName));

        $extensions = $this->extensionDiscovery->discover();
        $extensions['_custom'] = $this->extensionDiscovery->discoverRootProject();
        $skills = $this->skillDiscovery->discover($extensions);

        $skill = $this->findSkill($skills, $installedName);
        if (null === $skill) {
            $io->error(\sprintf('Unknown skill "%s". Run "skills:list" to see the available skills.', $installedName));

            return Command::FAILURE;
        }

        $this->extensionConfigSynchronizer->setSkillEnabled($skill->package, $skill->originalName, $this->targetEnabled());

        $result = $this->installer->install($skills, $this->extensionConfigSynchronizer->read());

        if ($this->targetEnabled()) {
            $io->success(\sprintf('Enabled skill "%s". It is now visible to the agent.', $installedName));
        } else {
            $io->success(\sprintf('Disabled skill "%s". It is hidden from the agent but its intent is kept in mate/extensions.php.', $installedName));
        }

        foreach ($result->notices as $notice) {
            $io->note($notice);
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<DiscoveredSkill> $skills
     */
    private function findSkill(array $skills, string $installedName): ?DiscoveredSkill
    {
        foreach ($skills as $skill) {
            if ($skill->installedName === $installedName) {
                return $skill;
            }
        }

        return null;
    }
}
