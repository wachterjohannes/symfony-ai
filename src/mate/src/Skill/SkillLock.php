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

/**
 * Reads and writes mate/skills.lock.php, the machine-managed record of how each skill was installed.
 *
 * The lock stores facts (how a skill actually got installed), never intent (what the user wants).
 * Intent lives in mate/extensions.php. The file is regenerated on every skills:install run and is
 * safe to commit or gitignore.
 *
 * @phpstan-type SkillLockEntry array{
 *     package: string,
 *     original_name: string,
 *     source: string,
 *     strategy: string,
 *     content_hash: string,
 *     targets: list<string>,
 *     overridden: bool,
 * }
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillLock
{
    public function __construct(
        private string $rootDir,
    ) {
    }

    public function path(): string
    {
        return $this->rootDir.'/mate/skills.lock.php';
    }

    public function exists(): bool
    {
        return file_exists($this->path());
    }

    /**
     * @return array<string, SkillLockEntry>
     */
    public function read(): array
    {
        $path = $this->path();
        if (!file_exists($path)) {
            return [];
        }

        $data = include $path;
        if (!\is_array($data)) {
            return [];
        }

        $entries = [];
        foreach ($data as $installedName => $entry) {
            if (!\is_string($installedName) || !\is_array($entry)) {
                continue;
            }

            $normalized = $this->normalizeEntry($entry);
            if (null !== $normalized) {
                $entries[$installedName] = $normalized;
            }
        }

        return $entries;
    }

    /**
     * @param array<string, SkillLockEntry> $entries
     */
    public function write(array $entries): void
    {
        ksort($entries);

        $dir = \dirname($this->path());
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = "<?php\n\n";
        $content .= "// This file is managed by 'mate skills:install'. Do not edit by hand.\n";
        $content .= "// It records how each skill was installed (facts, not intent).\n\n";
        // Skill/package names originate from third-party composer.json files and are written into a
        // PHP file that is later included; var_export() escapes every value safely.
        $content .= 'return '.var_export($entries, true).";\n";

        file_put_contents($this->path(), $content);
    }

    /**
     * @param array<array-key, mixed> $entry
     *
     * @return SkillLockEntry|null
     */
    private function normalizeEntry(array $entry): ?array
    {
        $package = $entry['package'] ?? null;
        $originalName = $entry['original_name'] ?? null;
        $source = $entry['source'] ?? null;
        $strategy = $entry['strategy'] ?? null;
        $contentHash = $entry['content_hash'] ?? null;
        $targets = $entry['targets'] ?? null;
        $overridden = $entry['overridden'] ?? null;

        if (!\is_string($package) || !\is_string($originalName) || !\is_string($source)) {
            return null;
        }

        if (!\is_string($strategy) || !\is_string($contentHash) || !\is_array($targets) || !\is_bool($overridden)) {
            return null;
        }

        $normalizedTargets = [];
        foreach ($targets as $target) {
            if (\is_string($target)) {
                $normalizedTargets[] = $target;
            }
        }

        return [
            'package' => $package,
            'original_name' => $originalName,
            'source' => $source,
            'strategy' => $strategy,
            'content_hash' => $contentHash,
            'targets' => $normalizedTargets,
            'overridden' => $overridden,
        ];
    }
}
