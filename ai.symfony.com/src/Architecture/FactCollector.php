<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Architecture;

/**
 * Reads the ground truth the architecture diagram specs are checked against:
 * which src/* packages exist, which bridges they ship, and whether a path
 * referenced by a spec still exists.
 *
 * @author Johannes Wachter <wachter.johannes@gmail.com>
 */
final readonly class FactCollector
{
    public function __construct(
        private string $projectDir,
    ) {
    }

    /**
     * @return array<string, array{require: list<string>, requireDev: list<string>, bridges: list<string>}>
     */
    public function packages(): array
    {
        $packages = [];

        foreach (glob($this->repoDir().'/src/*', \GLOB_ONLYDIR) ?: [] as $dir) {
            $composerFile = $dir.'/composer.json';
            if (!is_file($composerFile)) {
                continue;
            }

            $composer = json_decode((string) file_get_contents($composerFile), true, flags: \JSON_THROW_ON_ERROR);

            $packages[basename($dir)] = [
                'require' => array_keys($composer['require'] ?? []),
                'requireDev' => array_keys($composer['require-dev'] ?? []),
                'bridges' => $this->bridgeDirectories($dir),
            ];
        }

        ksort($packages);

        return $packages;
    }

    public function pathExists(string $repoRelativePath): bool
    {
        return is_file($this->repoDir().'/'.$repoRelativePath) || is_dir($this->repoDir().'/'.$repoRelativePath);
    }

    /**
     * @return list<string>
     */
    private function bridgeDirectories(string $packageDir): array
    {
        $bridgeDir = $packageDir.'/src/Bridge';
        if (!is_dir($bridgeDir)) {
            return [];
        }

        $entries = array_filter(
            scandir($bridgeDir) ?: [],
            static fn (string $entry): bool => !str_starts_with($entry, '.') && is_dir($bridgeDir.'/'.$entry),
        );

        sort($entries);

        return array_values($entries);
    }

    private function repoDir(): string
    {
        return \dirname($this->projectDir);
    }
}
