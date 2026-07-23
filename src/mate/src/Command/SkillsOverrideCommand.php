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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Take ownership of a skill by materializing an editable copy (ownership axis).
 *
 * Copies the current source into mate/skills/<name>/ (user source), sets intent override => true
 * (which also implies enabled => true) and re-runs the installer so the generated folders are built
 * from the override. Idempotent: re-running on an already-overridden skill is a no-op with a notice,
 * so user edits are never clobbered.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[AsCommand('skills:override', 'Take ownership of a Mate skill by materializing an editable copy')]
class SkillsOverrideCommand extends Command
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
        return 'skills:override';
    }

    public static function getDefaultDescription(): string
    {
        return 'Take ownership of a Mate skill by materializing an editable copy';
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Installed skill name (e.g. mate-system-information)')
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command takes ownership of a managed skill. It copies the current
source into <comment>mate/skills/&lt;name&gt;/</comment> (which becomes your editable, committable
source), records <comment>override => true</comment> in <comment>mate/extensions.php</comment>
(implying <comment>enabled => true</comment>), and rebuilds the generated folders from your copy.

From then on <comment>skills:install</comment> treats the skill as hands-off and never overwrites
your copy. Re-running this command on an already-overridden skill changes nothing. Use
<comment>skills:reset</comment> to hand ownership back to the extension.
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
        $alreadyOverridden = ($intent[$skill->package]['skills'][$skill->originalName]['override'] ?? false)
            && $this->overrideManager->exists($skill->originalName);

        if ($alreadyOverridden) {
            $io->note(\sprintf('Skill "%s" is already overridden at %s. Nothing to do.', $installedName, $this->relativeOverridePath($skill)));

            return Command::SUCCESS;
        }

        $copied = $this->overrideManager->materialize($skill);
        $this->extensionConfigSynchronizer->setSkillOverride($skill->package, $skill->originalName, true);
        $this->installer->install($skills, $this->extensionConfigSynchronizer->read());

        if ($copied) {
            $io->success(\sprintf('Overrode skill "%s". Edit your copy at %s; it will not be overwritten by skills:install.', $installedName, $this->relativeOverridePath($skill)));
        } else {
            $io->success(\sprintf('Overrode skill "%s" using the existing copy at %s.', $installedName, $this->relativeOverridePath($skill)));
        }

        return Command::SUCCESS;
    }

    private function relativeOverridePath(DiscoveredSkill $skill): string
    {
        return 'mate/skills/'.$skill->originalName;
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
