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

use Psr\Log\LoggerInterface;
use Symfony\AI\Mate\Skill\Model\DiscoveredSkill;
use Symfony\AI\Mate\Skill\Model\SkillInstallResult;

/**
 * Idempotent reconciler that rebuilds the generated skill folders from source + intent.
 *
 * The generated folders (.agents/skills/, .claude/skills/) are disposable output: every run
 * rebuilds them fully from either the vendor/package source (managed skill) or the user's
 * mate/skills/<name>/ copy (overridden skill). Override is a "hands off" flag — this installer
 * never writes into mate/skills/. Skills of disabled extensions, or with intent enabled=false,
 * are pruned from the generated folders. The lock is rewritten to mirror reality.
 *
 * @phpstan-type SkillIntent array{enabled?: bool, override?: bool}
 * @phpstan-type ExtensionIntent array{enabled?: bool, skills?: array<string, SkillIntent>}
 *
 * @phpstan-import-type SkillLockEntry from SkillLock
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillInstaller
{
    private const AGENTS_SKILLS_DIR = '.agents/skills';
    private const CLAUDE_SKILLS_DIR = '.claude/skills';
    private const GITIGNORE_START_MARKER = '# BEGIN AI_MATE_SKILLS';
    private const GITIGNORE_END_MARKER = '# END AI_MATE_SKILLS';

    public function __construct(
        private string $rootDir,
        private SkillFrontmatter $frontmatter,
        private SkillLock $lock,
        private SkillContentHasher $hasher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<DiscoveredSkill>          $skills
     * @param array<string, ExtensionIntent> $intent keyed by owner ("_custom" for the root project)
     */
    public function install(array $skills, array $intent): SkillInstallResult
    {
        $previousLock = $this->lock->read();

        $built = [];
        $installed = [];
        $skipped = [];
        $notices = [];

        foreach ($skills as $skill) {
            $ownerIntent = $intent[$skill->package] ?? [];
            $ownerEnabled = $ownerIntent['enabled'] ?? true;
            $skillIntent = $ownerIntent['skills'][$skill->originalName] ?? [];
            $enabled = $skillIntent['enabled'] ?? true;
            $override = $skillIntent['override'] ?? false;

            if ($override && !$enabled) {
                $notices[] = \sprintf('Skill "%s" is overridden but disabled; the override copy is kept but the skill stays hidden.', $skill->installedName);
            }

            if (!$ownerEnabled || !$enabled) {
                continue;
            }

            $sourceDir = $override ? $this->overrideSourceDir($skill) : $skill->absolutePath;
            if (!is_dir($sourceDir) || !is_file($sourceDir.'/SKILL.md')) {
                $this->logger->warning('Skipping skill with missing source', [
                    'skill' => $skill->installedName,
                    'source' => $sourceDir,
                    'overridden' => $override,
                ]);
                $skipped[$skill->installedName] = $override
                    ? 'override source missing in mate/skills/'
                    : 'source directory missing';
                continue;
            }

            $entry = $this->buildSkill($skill, $sourceDir, $override);
            $built[$skill->installedName] = $entry;

            if (!isset($previousLock[$skill->installedName])) {
                $installed[] = $skill->installedName;
            }
        }

        $removed = $this->prune($built, $previousLock);

        $this->writeGitignoreBlock([] !== $built);

        $this->lock->write($built);

        $active = array_keys($built);
        sort($active);

        return new SkillInstallResult($installed, $removed, $skipped, $active, $notices);
    }

    /**
     * @return SkillLockEntry
     */
    private function buildSkill(DiscoveredSkill $skill, string $sourceDir, bool $override): array
    {
        $agentsTarget = $this->rootDir.'/'.self::AGENTS_SKILLS_DIR.'/'.$skill->installedName;
        $claudeTarget = $this->rootDir.'/'.self::CLAUDE_SKILLS_DIR.'/'.$skill->installedName;

        $this->removePath($agentsTarget);
        $this->copyDirectory($sourceDir, $agentsTarget);
        $this->rewriteSkillName($agentsTarget.'/SKILL.md', $skill->installedName);

        $this->linkMirror($claudeTarget, $skill->installedName);

        return [
            'package' => $skill->package,
            'original_name' => $skill->originalName,
            'source' => $skill->source,
            'strategy' => 'copy',
            'content_hash' => $this->hasher->hash($agentsTarget) ?? 'sha256:',
            'targets' => [
                self::AGENTS_SKILLS_DIR.'/'.$skill->installedName,
                self::CLAUDE_SKILLS_DIR.'/'.$skill->installedName,
            ],
            'overridden' => $override,
        ];
    }

    private function overrideSourceDir(DiscoveredSkill $skill): string
    {
        return $this->rootDir.'/mate/skills/'.$skill->originalName;
    }

    /**
     * @param array<string, SkillLockEntry> $built
     * @param array<string, SkillLockEntry> $previousLock
     *
     * @return list<string>
     */
    private function prune(array $built, array $previousLock): array
    {
        $obsolete = [];
        foreach (array_keys($previousLock) as $installedName) {
            if (!isset($built[$installedName])) {
                $obsolete[$installedName] = true;
            }
        }

        foreach ([self::AGENTS_SKILLS_DIR, self::CLAUDE_SKILLS_DIR] as $baseDir) {
            $absoluteBase = $this->rootDir.'/'.$baseDir;
            if (!is_dir($absoluteBase)) {
                continue;
            }

            $entries = scandir($absoluteBase);
            if (false === $entries) {
                continue;
            }

            foreach ($entries as $entry) {
                if ('.' === $entry || '..' === $entry || !str_starts_with($entry, 'mate-')) {
                    continue;
                }

                if (!isset($built[$entry])) {
                    $obsolete[$entry] = true;
                }
            }
        }

        foreach (array_keys($obsolete) as $installedName) {
            $this->removePath($this->rootDir.'/'.self::AGENTS_SKILLS_DIR.'/'.$installedName);
            $this->removePath($this->rootDir.'/'.self::CLAUDE_SKILLS_DIR.'/'.$installedName);
        }

        $removed = array_keys($obsolete);
        sort($removed);

        return $removed;
    }

    private function rewriteSkillName(string $skillFile, string $installedName): void
    {
        $content = file_get_contents($skillFile);
        if (false === $content) {
            return;
        }

        $rewritten = $this->frontmatter->rewriteName($content, $installedName);
        if ($rewritten !== $content) {
            file_put_contents($skillFile, $rewritten);
        }
    }

    private function linkMirror(string $mirrorPath, string $installedName): void
    {
        $this->removePath($mirrorPath);

        $mirrorDir = \dirname($mirrorPath);
        if (!is_dir($mirrorDir)) {
            mkdir($mirrorDir, 0755, true);
        }

        $relativeTarget = '../../'.self::AGENTS_SKILLS_DIR.'/'.$installedName;

        if (!@symlink($relativeTarget, $mirrorPath)) {
            $this->logger->warning('Failed to create skill mirror symlink; the canonical copy in .agents/skills/ is still usable', [
                'mirror' => $mirrorPath,
                'target' => $relativeTarget,
            ]);
        }
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

    private function writeGitignoreBlock(bool $hasSkills): void
    {
        $path = $this->rootDir.'/.gitignore';

        if (!$hasSkills) {
            return;
        }

        $block = implode("\n", [
            self::GITIGNORE_START_MARKER,
            '# Generated skill folders, rebuildable via `vendor/bin/mate skills:install`.',
            '/'.self::AGENTS_SKILLS_DIR.'/',
            '/'.self::CLAUDE_SKILLS_DIR.'/',
            self::GITIGNORE_END_MARKER,
        ]);

        if (!file_exists($path)) {
            file_put_contents($path, $block."\n");

            return;
        }

        $content = file_get_contents($path);
        if (false === $content) {
            return;
        }

        file_put_contents($path, $this->replaceGitignoreBlock($content, $block));
    }

    private function replaceGitignoreBlock(string $content, string $block): string
    {
        $startPos = strpos($content, self::GITIGNORE_START_MARKER);
        $endPos = strpos($content, self::GITIGNORE_END_MARKER);

        if (false === $startPos || false === $endPos || $endPos < $startPos) {
            $trimmed = rtrim($content);
            if ('' === $trimmed) {
                return $block."\n";
            }

            return $trimmed."\n\n".$block."\n";
        }

        $endPos += \strlen(self::GITIGNORE_END_MARKER);

        $prefix = rtrim(substr($content, 0, $startPos));
        $suffix = ltrim(substr($content, $endPos));

        $newContent = $block;
        if ('' !== $prefix) {
            $newContent = $prefix."\n\n".$block;
        }

        if ('' !== $suffix) {
            $newContent .= "\n\n".$suffix;
        }

        return rtrim($newContent)."\n";
    }

    private function removePath(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if (!file_exists($path)) {
            return;
        }

        if (is_file($path)) {
            unlink($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
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

        rmdir($path);
    }
}
