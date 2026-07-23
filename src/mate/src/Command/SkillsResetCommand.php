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
use Symfony\AI\Mate\Skill\SkillOverrideManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Hand ownership of a skill back to its extension (ownership axis).
 *
 * Removes the user override copy in mate/skills/<name>/, sets intent override => false and re-runs
 * the installer so the generated folders are rebuilt from the managed source. This destroys user
 * work, so it requires --force (or an interactive confirmation).
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[AsCommand('skills:reset', 'Discard a Mate skill override and return to the managed copy')]
class SkillsResetCommand extends Command
{
    public function __construct(
        private ComposerExtensionDiscovery $extensionDiscovery,
        private ExtensionConfigSynchronizer $extensionConfigSynchronizer,
        private SkillDiscovery $skillDiscovery,
        private SkillOverrideManager $overrideManager,
        private SkillInstaller $installer,
    ) {
        parent::__construct(self::getDefaultName());
    }

    public static function getDefaultName(): string
    {
        return 'skills:reset';
    }

    public static function getDefaultDescription(): string
    {
        return 'Discard a Mate skill override and return to the managed copy';
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Installed skill name (e.g. mate-system-information)')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Confirm removal of the override copy without prompting')
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command discards a skill override and hands ownership back to the
extension. It <comment>deletes</comment> your editable copy in
<comment>mate/skills/&lt;name&gt;/</comment>, records <comment>override => false</comment> in
<comment>mate/extensions.php</comment>, and rebuilds the generated folders from the managed source.

Because this destroys user work, it refuses to run without <comment>--force</comment> (or an
interactive confirmation) and prints exactly what would be removed.
HELP
            );
    }

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

        $intent = $this->extensionConfigSynchronizer->read();
        $overridden = ($intent[$skill->package]['skills'][$skill->originalName]['override'] ?? false)
            || $this->overrideManager->exists($skill->originalName);

        if (!$overridden) {
            $io->note(\sprintf('Skill "%s" is not overridden. Nothing to reset.', $installedName));

            return Command::SUCCESS;
        }

        $overridePath = 'mate/skills/'.$skill->originalName;

        $force = (bool) $input->getOption('force');
        if (!$force) {
            $io->warning(\sprintf('This will permanently delete your override copy at %s.', $overridePath));

            if (!$input->isInteractive() || !$io->confirm('Do you want to continue?', false)) {
                $io->error('Aborted. Re-run with --force to discard the override.');

                return Command::FAILURE;
            }
        }

        $this->overrideManager->remove($skill->originalName);
        $this->extensionConfigSynchronizer->setSkillOverride($skill->package, $skill->originalName, false);
        $this->installer->install($skills, $this->extensionConfigSynchronizer->read());

        $io->success(\sprintf('Reset skill "%s". Removed %s and rebuilt it from the managed source.', $installedName, $overridePath));

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
