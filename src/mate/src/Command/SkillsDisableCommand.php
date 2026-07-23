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
 * Disable a skill so the agent no longer sees it (visibility axis).
 *
 * Disabling is the user's consent veto over auto-loaded foreign instruction text: it hides the
 * skill from the generated folders but keeps its intent in mate/extensions.php and destroys
 * nothing on disk, so no confirmation is required.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[AsCommand('skills:disable', 'Disable a Mate skill so the agent no longer sees it')]
class SkillsDisableCommand extends AbstractSkillVisibilityCommand
{
    public static function getDefaultName(): string
    {
        return 'skills:disable';
    }

    public static function getDefaultDescription(): string
    {
        return 'Disable a Mate skill so the agent no longer sees it';
    }

    protected function configure(): void
    {
        parent::configure();

        $this->setHelp(
            <<<'HELP'
The <info>%command.name%</info> command is your <comment>consent veto</comment> over a skill's
auto-loaded instruction text. It flips the skill's <comment>enabled</comment> intent off in
<comment>mate/extensions.php</comment> and re-syncs the generated folders so the agent no longer
sees it.

Disabling destroys nothing: the intent stays recorded and the skill can be re-enabled at any time
with <comment>skills:enable</comment>. It does not remove an override copy in
<comment>mate/skills/</comment> (use <comment>skills:reset</comment> for that).
HELP
        );
    }

    protected function targetEnabled(): bool
    {
        return false;
    }
}
