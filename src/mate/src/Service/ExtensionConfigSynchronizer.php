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
use Symfony\AI\Mate\Exception\InvalidPackageNameException;

/**
 * Synchronizes discovered extensions with mate/extensions.php while preserving enabled flags.
 *
 * @phpstan-import-type ExtensionData from ComposerExtensionDiscovery
 *
 * @phpstan-type ExtensionConfig array{enabled: bool}
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
    // Secure file permissions: owner read/write, group read, others nothing
    private const FILE_MODE = 0640;

    // Secure directory permissions: owner read/write/execute, group read/execute, others nothing
    private const DIR_MODE = 0750;

    public function __construct(
        private string $rootDir,
    ) {
    }

    public function extensionsFileExists(): bool
    {
        return file_exists($this->rootDir.'/mate/extensions.php');
    }

    /**
     * @param array<string, ExtensionData> $discoveredExtensions
     *
     * @return SynchronizationResult
     */
    public function synchronize(array $discoveredExtensions): array
    {
        $extensionsFile = $this->rootDir.'/mate/extensions.php';
        $existingExtensions = $this->readExistingExtensions($extensionsFile);

        $newPackages = [];
        foreach (array_keys($discoveredExtensions) as $packageName) {
            if (!isset($existingExtensions[$packageName])) {
                $newPackages[] = $packageName;
            }
        }

        $removedPackages = [];
        foreach (array_keys($existingExtensions) as $packageName) {
            if (!isset($discoveredExtensions[$packageName])) {
                $removedPackages[] = $packageName;
            }
        }

        $finalExtensions = [];
        foreach (array_keys($discoveredExtensions) as $packageName) {
            $enabled = true;
            if (isset($existingExtensions[$packageName]) && \is_array($existingExtensions[$packageName])) {
                $enabledValue = $existingExtensions[$packageName]['enabled'] ?? true;
                if (\is_bool($enabledValue)) {
                    $enabled = $enabledValue;
                }
            }

            // Validate package name before using it in file generation
            $this->validatePackageName($packageName);

            $finalExtensions[$packageName] = [
                'enabled' => $enabled,
            ];
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
     * @return array<string, array{enabled?: bool}>
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

        /* @var array<string, array{enabled?: bool}> $existingExtensions */
        return $existingExtensions;
    }

    /**
     * Validate that a package name is safe to use in PHP code generation.
     *
     * @throws InvalidPackageNameException If the package name contains unsafe characters
     */
    private function validatePackageName(string $packageName): void
    {
        // Package names should only contain alphanumeric characters, hyphens, underscores, slashes, and dots
        // This follows Composer package naming conventions
        if (!preg_match('/^[a-z0-9_\-\.\/]+$/i', $packageName)) {
            throw new InvalidPackageNameException(\sprintf(
                'Invalid package name "%s": package names can only contain alphanumeric characters, hyphens, underscores, dots, and slashes.',
                $packageName
            ));
        }

        // Additional safety: ensure the package name doesn't contain PHP special characters
        // that could break the generated PHP code
        $forbiddenChars = ['\'', '"', '$', ';', '{', '}', '?', '>', '<', '|', '&', '~', '`'];
        foreach ($forbiddenChars as $char) {
            if (str_contains($packageName, $char)) {
                throw new InvalidPackageNameException(\sprintf(
                    'Invalid package name "%s": package name contains forbidden character "%s".',
                    $packageName,
                    $char
                ));
            }
        }
    }

    /**
     * @param ExtensionConfigMap $extensions
     */
    private function writeExtensionsFile(string $filePath, array $extensions): void
    {
        $dir = \dirname($filePath);
        if (!is_dir($dir)) {
            $oldUmask = umask(0027); // Restrict group and others to read/execute only
            mkdir($dir, self::DIR_MODE, true);
            umask($oldUmask);
            @chmod($dir, self::DIR_MODE);
        }

        $content = "<?php\n\n";
        $content .= "// This file is managed by 'mate discover'\n";
        $content .= "// You can manually edit to enable/disable extensions\n\n";
        $content .= "return [\n";

        foreach ($extensions as $packageName => $config) {
            // Sanitize package name for use in PHP string context
            $safePackageName = $this->sanitizePackageNameForPhp($packageName);
            $enabled = $config['enabled'] ? 'true' : 'false';
            $content .= "    '$safePackageName' => ['enabled' => $enabled],\n";
        }

        $content .= "];\n";

        file_put_contents($filePath, $content);
        @chmod($filePath, self::FILE_MODE);
    }

    /**
     * Sanitize a package name for safe use in PHP string context.
     * Escapes single quotes to prevent PHP code injection.
     */
    private function sanitizePackageNameForPhp(string $packageName): string
    {
        // Escape single quotes by replacing them with escaped single quotes
        return str_replace("'", "\\'", $packageName);
    }
}
