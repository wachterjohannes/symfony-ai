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
 * Compares what the architecture diagram specs under docs/architecture/ claim
 * against the real repository. A spec pointing at a path that no longer
 * exists is an error; a package under src/* that no spec's sources[] mentions
 * at all is a warning, not an error, so an ordinary bridge PR is never
 * blocked on updating a diagram.
 *
 * @author Johannes Wachter <wachter.johannes@gmail.com>
 */
final readonly class DriftChecker
{
    public function __construct(
        private FactCollector $facts,
        private string $projectDir,
    ) {
    }

    /**
     * @return array{errors: list<string>, warnings: list<string>}
     */
    public function check(): array
    {
        $errors = [];
        $mentionedPackages = [];

        foreach ($this->specs() as $specFile => $spec) {
            $basename = basename($specFile);

            foreach ($spec['components'] ?? [] as $component) {
                foreach ($component['sources'] ?? [] as $source) {
                    $path = \is_array($source) ? ($source['path'] ?? null) : $source;
                    if (!\is_string($path)) {
                        continue;
                    }

                    if (!$this->facts->pathExists($path)) {
                        $errors[] = \sprintf('%s: component "%s" references "%s", which no longer exists.', $basename, $component['id'] ?? '?', $path);

                        continue;
                    }

                    if (null !== ($package = $this->packageFromPath($path))) {
                        $mentionedPackages[$package] = true;
                    }
                }
            }
        }

        $warnings = [];
        foreach (array_keys($this->facts->packages()) as $package) {
            if (!isset($mentionedPackages[$package])) {
                $warnings[] = \sprintf('src/%s has no source reference in any architecture diagram spec yet.', $package);
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * @return iterable<string, array<string, mixed>> spec file path => decoded spec
     */
    private function specs(): iterable
    {
        $specDir = \dirname($this->projectDir).'/docs/architecture';

        foreach (glob($specDir.'/*.json') ?: [] as $specFile) {
            yield $specFile => json_decode((string) file_get_contents($specFile), true, flags: \JSON_THROW_ON_ERROR);
        }
    }

    private function packageFromPath(string $repoRelativePath): ?string
    {
        return 1 === preg_match('#^src/([^/]+)/#', $repoRelativePath, $matches) ? $matches[1] : null;
    }
}
