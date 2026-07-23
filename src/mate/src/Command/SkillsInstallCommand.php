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
use Symfony\AI\Mate\Skill\Model\SkillInstallResult;
use Symfony\AI\Mate\Skill\SkillDiscovery;
use Symfony\AI\Mate\Skill\SkillInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reconcile the generated skill folders from source + intent.
 *
 * This is the one idempotent reconciler: it rebuilds .agents/skills/ and .claude/skills/ from the
 * vendor sources (or user overrides in mate/skills/), prunes skills of disabled or removed
 * extensions, and rewrites mate/skills.lock.php. It never edits mate/extensions.php (intent) and
 * never writes into mate/skills/ (user-owned overrides).
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[AsCommand('skills:install', 'Install and reconcile Mate skills into the generated agent folders')]
class SkillsInstallCommand extends Command
{
    public function __construct(
        private ComposerExtensionDiscovery $extensionDiscovery,
        private ExtensionConfigSynchronizer $extensionConfigSynchronizer,
        private SkillDiscovery $skillDiscovery,
        private SkillInstaller $installer,
    ) {
        parent::__construct(self::getDefaultName());
    }

    public static function getDefaultName(): string
    {
        return 'skills:install';
    }

    public static function getDefaultDescription(): string
    {
        return 'Install and reconcile Mate skills into the generated agent folders';
    }

    protected function configure(): void
    {
        $this->setHelp(
            <<<'HELP'
The <info>%command.name%</info> command rebuilds the generated skill folders
(<comment>.agents/skills/</comment> and <comment>.claude/skills/</comment>) from the skills declared
by installed Mate extensions and the root project.

It reads intent from <comment>mate/extensions.php</comment> (which skills are enabled and which are
overridden) and writes facts to <comment>mate/skills.lock.php</comment>. The command is idempotent:
running it repeatedly converges on the same result and never touches your overrides in
<comment>mate/skills/</comment>.
HELP
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $extensions = $this->extensionDiscovery->discover();
        $extensions['_custom'] = $this->extensionDiscovery->discoverRootProject();

        $skills = $this->skillDiscovery->discover($extensions);
        $intent = $this->extensionConfigSynchronizer->read();

        $result = $this->installer->install($skills, $intent);

        $this->render($io, $result);

        return Command::SUCCESS;
    }

    private function render(SymfonyStyle $io, SkillInstallResult $result): void
    {
        $io->title('Skill Installation');

        if ([] !== $result->installed) {
            $io->success(\sprintf('Installed %d new skill%s: %s', \count($result->installed), 1 === \count($result->installed) ? '' : 's', implode(', ', $result->installed)));
        }

        if ([] !== $result->removed) {
            $io->text(\sprintf('Removed %d skill%s: %s', \count($result->removed), 1 === \count($result->removed) ? '' : 's', implode(', ', $result->removed)));
        }

        foreach ($result->skipped as $name => $reason) {
            $io->warning(\sprintf('Skipped %s: %s', $name, $reason));
        }

        foreach ($result->notices as $notice) {
            $io->note($notice);
        }

        if ([] === $result->active) {
            $io->text('No skills are currently installed.');

            return;
        }

        $io->text(\sprintf('%d skill%s installed: %s', \count($result->active), 1 === \count($result->active) ? '' : 's', implode(', ', $result->active)));
    }
}
