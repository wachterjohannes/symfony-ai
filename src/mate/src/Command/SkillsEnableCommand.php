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

use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Enable a skill so the agent sees it again (visibility axis).
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[AsCommand('skills:enable', 'Enable a Mate skill so the agent can see it')]
class SkillsEnableCommand extends AbstractSkillVisibilityCommand
{
    public static function getDefaultName(): string
    {
        return 'skills:enable';
    }

    public static function getDefaultDescription(): string
    {
        return 'Enable a Mate skill so the agent can see it';
    }

    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(
            <<<'HELP'
The <info>%command.name%</info> command flips a skill's <comment>enabled</comment> intent back on
in <comment>mate/extensions.php</comment> and re-syncs the generated skill folders.

Enabling is the reverse of the consent veto: the skill becomes visible to the agent again. It is
harmless and reversible, so no confirmation is required.
HELP
        );
    }

    protected function targetEnabled(): bool
    {
        return true;
    }
}
