<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Service;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Service\ExtensionConfigSynchronizer;
use Symfony\AI\Mate\Skill\Model\DiscoveredSkill;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class ExtensionConfigSynchronizerTest extends TestCase
{
    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/mate-synchronizer-'.uniqid();
        mkdir($this->rootDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $file = $this->rootDir.'/mate/extensions.php';
        if (is_file($file)) {
            unlink($file);
        }
        foreach ([$this->rootDir.'/mate', $this->rootDir] as $dir) {
            if (is_dir($dir)) {
                rmdir($dir);
            }
        }
    }

    public function testWritesValidPackageNames()
    {
        $synchronizer = new ExtensionConfigSynchronizer($this->rootDir);

        $synchronizer->synchronize(['vendor/package-a' => ['dirs' => [], 'includes' => []]]);

        $extensions = include $this->rootDir.'/mate/extensions.php';

        $this->assertSame(['vendor/package-a' => ['enabled' => true]], $extensions);
    }

    public function testMaliciousPackageNameCannotInjectCode()
    {
        $synchronizer = new ExtensionConfigSynchronizer($this->rootDir);

        // A crafted package name that would break out of the single-quoted string literal
        // and inject additional array entries / PHP code under naive interpolation.
        $maliciousName = "evil', 'injected' => ['enabled' => true], 'x";

        $synchronizer->synchronize([$maliciousName => ['dirs' => [], 'includes' => []]]);

        $extensions = include $this->rootDir.'/mate/extensions.php';

        // The whole crafted string must round-trip as a single key, and no extra entry is created.
        $this->assertSame([$maliciousName => ['enabled' => true]], $extensions);
        $this->assertArrayNotHasKey('injected', $extensions);
    }

    public function testAddsNewlyDiscoveredSkillsWithDefaultIntent()
    {
        $synchronizer = new ExtensionConfigSynchronizer($this->rootDir);

        $synchronizer->synchronize(
            ['vendor/package-a' => ['dirs' => [], 'includes' => []]],
            [$this->skill('vendor/package-a', 'system-information')],
        );

        $extensions = include $this->rootDir.'/mate/extensions.php';

        $this->assertSame([
            'vendor/package-a' => [
                'enabled' => true,
                'skills' => [
                    'system-information' => ['enabled' => true, 'override' => false],
                ],
            ],
        ], $extensions);
    }

    public function testPreservesExistingSkillIntentAndDropsVanishedSkills()
    {
        mkdir($this->rootDir.'/mate', 0777, true);
        file_put_contents($this->rootDir.'/mate/extensions.php', <<<'PHP'
            <?php
            return [
                'vendor/package-a' => [
                    'enabled' => true,
                    'skills' => [
                        'system-information' => ['enabled' => false, 'override' => true],
                        'gone' => ['enabled' => true, 'override' => false],
                    ],
                ],
            ];
            PHP);

        $synchronizer = new ExtensionConfigSynchronizer($this->rootDir);

        $synchronizer->synchronize(
            ['vendor/package-a' => ['dirs' => [], 'includes' => []]],
            [$this->skill('vendor/package-a', 'system-information')],
        );

        $extensions = include $this->rootDir.'/mate/extensions.php';

        // override implies enabled: the disabled+overridden state is normalized to enabled.
        $this->assertSame([
            'vendor/package-a' => [
                'enabled' => true,
                'skills' => [
                    'system-information' => ['enabled' => true, 'override' => true],
                ],
            ],
        ], $extensions);
    }

    public function testEmitsCustomEntryForRootProjectSkills()
    {
        $synchronizer = new ExtensionConfigSynchronizer($this->rootDir);

        $synchronizer->synchronize(
            [],
            [$this->skill('_custom', 'project-notes')],
        );

        $extensions = include $this->rootDir.'/mate/extensions.php';

        $this->assertArrayHasKey('_custom', $extensions);
        $this->assertSame(['project-notes' => ['enabled' => true, 'override' => false]], $extensions['_custom']['skills']);
    }

    public function testReadReturnsNormalizedIntent()
    {
        mkdir($this->rootDir.'/mate', 0777, true);
        file_put_contents($this->rootDir.'/mate/extensions.php', <<<'PHP'
            <?php
            return [
                'vendor/package-a' => [
                    'enabled' => true,
                    'skills' => [
                        'system-information' => ['enabled' => true, 'override' => false],
                    ],
                ],
            ];
            PHP);

        $synchronizer = new ExtensionConfigSynchronizer($this->rootDir);

        $this->assertSame([
            'vendor/package-a' => [
                'enabled' => true,
                'skills' => [
                    'system-information' => ['enabled' => true, 'override' => false],
                ],
            ],
        ], $synchronizer->read());
    }

    private function skill(string $package, string $originalName): DiscoveredSkill
    {
        return new DiscoveredSkill(
            package: $package,
            originalName: $originalName,
            installedName: 'mate-'.$originalName,
            source: 'skills/'.$originalName,
            absolutePath: $this->rootDir.'/skills/'.$originalName,
            description: 'Test skill.',
        );
    }
}
