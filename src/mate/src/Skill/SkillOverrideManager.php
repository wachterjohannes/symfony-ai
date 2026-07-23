<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Skill;

use Symfony\AI\Mate\Skill\Model\DiscoveredSkill;

/**
 * Materializes and removes user-owned skill override copies under mate/skills/<name>/.
 *
 * The override copy is user source: once it exists it is never overwritten (skills:override is a
 * no-op on an already-overridden skill) and only skills:reset removes it. The installer builds the
 * generated folders from this copy but never writes into it.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillOverrideManager
{
    public function __construct(
        private string $rootDir,
    ) {
    }

    public function path(string $originalName): string
    {
        return $this->rootDir.'/mate/skills/'.$originalName;
    }

    public function exists(string $originalName): bool
    {
        return is_dir($this->path($originalName));
    }

    /**
     * Copies the skill's current source into mate/skills/<name>/.
     *
     * Returns false without touching anything when an override copy already exists, so existing user
     * edits are never clobbered.
     */
    public function materialize(DiscoveredSkill $skill): bool
    {
        $target = $this->path($skill->originalName);
        if (is_dir($target)) {
            return false;
        }

        $this->copyDirectory($skill->absolutePath, $target);

        return true;
    }

    /**
     * Removes the override copy for the given skill.
     *
     * Returns false when there was nothing to remove.
     */
    public function remove(string $originalName): bool
    {
        $target = $this->path($originalName);
        if (!is_dir($target)) {
            return false;
        }

        $this->removeDirectory($target);

        return true;
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        $prefixLength = \strlen($source) + 1;
        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            $target = $destination.'/'.substr($item->getPathname(), $prefixLength);

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }

                continue;
            }

            copy($item->getPathname(), $target);
        }
    }

    private function removeDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            \assert($item instanceof \SplFileInfo);
            if ($item->isLink() || $item->isFile()) {
                unlink($item->getPathname());

                continue;
            }

            rmdir($item->getPathname());
        }

        rmdir($directory);
    }
}
