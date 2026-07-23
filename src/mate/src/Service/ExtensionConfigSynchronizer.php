<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Service;

use Symfony\AI\Mate\Discovery\ComposerExtensionDiscovery;
use Symfony\AI\Mate\Skill\Model\DiscoveredSkill;

/**
 * Synchronizes discovered extensions with mate/extensions.php while preserving user intent.
 *
 * mate/extensions.php holds intent (what the user wants), never machine-derived facts. Each package
 * carries an "enabled" flag and an optional nested "skills" block, where every skill has exactly two
 * intent booleans: "enabled" (should the agent see it?) and "override" (does the user own the copy?).
 * Existing intent is preserved across runs; newly discovered skills default to enabled + not overridden;
 * skills whose source vanished are dropped.
 *
 * @phpstan-import-type ExtensionData from ComposerExtensionDiscovery
 *
 * @phpstan-type SkillIntent array{enabled: bool, override: bool}
 * @phpstan-type ExtensionConfig array{enabled: bool, skills?: array<string, SkillIntent>}
 * @phpstan-type ExtensionConfigMap array<string, ExtensionConfig>
 * @phpstan-type SynchronizationResult array{
 *     extensions: ExtensionConfigMap,
 *     new_packages: string[],
 *     removed_packages: string[],
 *     file: string,
 * }
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ExtensionConfigSynchronizer
{
    public function __construct(
        private string $rootDir,
    ) {
    }

    public function extensionsFileExists(): bool
    {
        return file_exists($this->rootDir.'/mate/extensions.php');
    }

    /**
     * Reads the current intent map, including per-skill intent, without writing anything.
     *
     * @return ExtensionConfigMap
     */
    public function read(): array
    {
        return $this->normalizeExistingExtensions($this->readExistingExtensions($this->rootDir.'/mate/extensions.php'));
    }

    /**
     * Sets the "override" intent of a single skill, adding the owner/skill entry when missing.
     *
     * Setting override to true also forces enabled to true (override implies enabled).
     */
    public function setSkillOverride(string $owner, string $skillName, bool $override): void
    {
        $intent = $this->read();

        if (!isset($intent[$owner])) {
            $intent[$owner] = ['enabled' => true];
        }

        $skills = $intent[$owner]['skills'] ?? [];
        $skill = $skills[$skillName] ?? ['enabled' => true, 'override' => false];

        $skill['override'] = $override;
        if ($override) {
            $skill['enabled'] = true;
        }

        $skills[$skillName] = $skill;
        ksort($skills);
        $intent[$owner]['skills'] = $skills;

        $this->writeExtensionsFile($this->rootDir.'/mate/extensions.php', $intent);
    }

    /**
     * @param array<string, ExtensionData> $discoveredExtensions
     * @param list<DiscoveredSkill>        $discoveredSkills
     *
     * @return SynchronizationResult
     */
    public function synchronize(array $discoveredExtensions, array $discoveredSkills = []): array
    {
        $extensionsFile = $this->rootDir.'/mate/extensions.php';
        $existingExtensions = $this->normalizeExistingExtensions($this->readExistingExtensions($extensionsFile));

        $skillsByOwner = $this->groupSkillsByOwner($discoveredSkills);

        $newPackages = [];
        foreach (array_keys($discoveredExtensions) as $packageName) {
            if (!isset($existingExtensions[$packageName])) {
                $newPackages[] = $packageName;
            }
        }

        $removedPackages = [];
        foreach (array_keys($existingExtensions) as $packageName) {
            if ('_custom' === $packageName) {
                continue;
            }

            if (!isset($discoveredExtensions[$packageName])) {
                $removedPackages[] = $packageName;
            }
        }

        // Owners that keep an entry: every discovered package, plus "_custom" when the root ships skills.
        $owners = array_keys($discoveredExtensions);
        if (isset($skillsByOwner['_custom'])) {
            $owners[] = '_custom';
        }

        $finalExtensions = [];
        foreach ($owners as $owner) {
            $enabled = true;
            if (isset($existingExtensions[$owner])) {
                $enabled = $existingExtensions[$owner]['enabled'];
            }

            $config = ['enabled' => $enabled];

            $skills = $this->synchronizeSkills(
                $existingExtensions[$owner]['skills'] ?? [],
                $skillsByOwner[$owner] ?? [],
            );
            if ([] !== $skills) {
                $config['skills'] = $skills;
            }

            $finalExtensions[$owner] = $config;
        }

        $this->writeExtensionsFile($extensionsFile, $finalExtensions);

        return [
            'extensions' => $finalExtensions,
            'new_packages' => $newPackages,
            'removed_packages' => $removedPackages,
            'file' => $extensionsFile,
        ];
    }

    /**
     * @param array<string, SkillIntent> $existingSkills
     * @param list<string>               $discoveredNames
     *
     * @return array<string, SkillIntent>
     */
    private function synchronizeSkills(array $existingSkills, array $discoveredNames): array
    {
        $skills = [];
        foreach ($discoveredNames as $name) {
            if (isset($existingSkills[$name])) {
                $skills[$name] = $existingSkills[$name];

                continue;
            }

            $skills[$name] = ['enabled' => true, 'override' => false];
        }

        ksort($skills);

        return $skills;
    }

    /**
     * @param list<DiscoveredSkill> $discoveredSkills
     *
     * @return array<string, list<string>>
     */
    private function groupSkillsByOwner(array $discoveredSkills): array
    {
        $grouped = [];
        foreach ($discoveredSkills as $skill) {
            $grouped[$skill->package][] = $skill->originalName;
        }

        return $grouped;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readExistingExtensions(string $extensionsFile): array
    {
        if (!file_exists($extensionsFile)) {
            return [];
        }

        $existingExtensions = include $extensionsFile;
        if (!\is_array($existingExtensions)) {
            return [];
        }

        $result = [];
        foreach ($existingExtensions as $packageName => $config) {
            if (\is_string($packageName) && \is_array($config)) {
                $result[$packageName] = $config;
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $existingExtensions
     *
     * @return ExtensionConfigMap
     */
    private function normalizeExistingExtensions(array $existingExtensions): array
    {
        $normalized = [];
        foreach ($existingExtensions as $packageName => $config) {
            $enabled = true;
            if (isset($config['enabled']) && \is_bool($config['enabled'])) {
                $enabled = $config['enabled'];
            }

            $entry = ['enabled' => $enabled];

            $skills = $this->normalizeSkills($config['skills'] ?? null);
            if ([] !== $skills) {
                $entry['skills'] = $skills;
            }

            $normalized[$packageName] = $entry;
        }

        return $normalized;
    }

    /**
     * @return array<string, SkillIntent>
     */
    private function normalizeSkills(mixed $skills): array
    {
        if (!\is_array($skills)) {
            return [];
        }

        $normalized = [];
        foreach ($skills as $name => $intent) {
            if (!\is_string($name) || !\is_array($intent)) {
                continue;
            }

            $override = isset($intent['override']) && true === $intent['override'];
            $enabled = !isset($intent['enabled']) || false !== $intent['enabled'];

            // override implies enabled: a disabled+overridden state is never produced by commands.
            if ($override) {
                $enabled = true;
            }

            $normalized[$name] = ['enabled' => $enabled, 'override' => $override];
        }

        return $normalized;
    }

    /**
     * @param ExtensionConfigMap $extensions
     */
    private function writeExtensionsFile(string $filePath, array $extensions): void
    {
        $dir = \dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = "<?php\n\n";
        $content .= "// This file is managed by 'mate discover'\n";
        $content .= "// You can manually edit to enable/disable extensions and their skills\n\n";
        $content .= "return [\n";

        foreach ($extensions as $packageName => $config) {
            $content .= $this->emitExtension($packageName, $config);
        }

        $content .= "];\n";

        file_put_contents($filePath, $content);
    }

    /**
     * @param ExtensionConfig $config
     */
    private function emitExtension(string $packageName, array $config): string
    {
        $enabled = $config['enabled'] ? 'true' : 'false';
        $skills = $config['skills'] ?? [];

        // Package/skill names originate from third-party composer.json files and are written into a
        // PHP file that is later included; var_export() escapes them safely to prevent code injection.
        if ([] === $skills) {
            return \sprintf("    %s => ['enabled' => %s],\n", var_export($packageName, true), $enabled);
        }

        $content = \sprintf("    %s => [\n", var_export($packageName, true));
        $content .= \sprintf("        'enabled' => %s,\n", $enabled);
        $content .= "        'skills' => [\n";
        foreach ($skills as $skillName => $intent) {
            $content .= \sprintf(
                "            %s => ['enabled' => %s, 'override' => %s],\n",
                var_export($skillName, true),
                $intent['enabled'] ? 'true' : 'false',
                $intent['override'] ? 'true' : 'false',
            );
        }
        $content .= "        ],\n";
        $content .= "    ],\n";

        return $content;
    }
}
