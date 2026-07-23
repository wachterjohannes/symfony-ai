<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Skill;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Mate\Skill\Model\DiscoveredSkill;
use Symfony\AI\Mate\Skill\SkillContentHasher;
use Symfony\AI\Mate\Skill\SkillDiscovery;
use Symfony\AI\Mate\Skill\SkillFrontmatter;
use Symfony\AI\Mate\Skill\SkillInstaller;
use Symfony\AI\Mate\Skill\SkillLock;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillInstallerTest extends TestCase
{
    use SkillFixtureTrait;

    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/mate-skill-installer-'.uniqid();
        mkdir($this->rootDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testFreshInstallBuildsGeneratedFoldersLockAndGitignore()
    {
        $this->createSkill($this->rootDir.'/vendor/vendor/pkg-a/skills', 'system-information', 'System info.');
        $skills = $this->discover();

        $result = $this->installer()->install($skills, [
            'vendor/pkg-a' => ['enabled' => true, 'skills' => ['system-information' => ['enabled' => true, 'override' => false]]],
        ]);

        $this->assertSame(['mate-system-information'], $result->installed);
        $this->assertSame(['mate-system-information'], $result->active);

        $agentsSkill = $this->rootDir.'/.agents/skills/mate-system-information';
        $this->assertDirectoryExists($agentsSkill);

        $content = file_get_contents($agentsSkill.'/SKILL.md');
        $this->assertIsString($content);
        $this->assertStringContainsString('name: mate-system-information', $content);

        $mirror = $this->rootDir.'/.claude/skills/mate-system-information';
        $this->assertTrue(is_link($mirror));
        $this->assertSame('../../.agents/skills/mate-system-information', readlink($mirror));

        $lock = (new SkillLock($this->rootDir))->read();
        $this->assertArrayHasKey('mate-system-information', $lock);
        $this->assertSame('vendor/pkg-a', $lock['mate-system-information']['package']);
        $this->assertSame('copy', $lock['mate-system-information']['strategy']);
        $this->assertFalse($lock['mate-system-information']['overridden']);
        $this->assertStringStartsWith('sha256:', $lock['mate-system-information']['content_hash']);

        $gitignore = file_get_contents($this->rootDir.'/.gitignore');
        $this->assertIsString($gitignore);
        $this->assertStringContainsString('/.agents/skills/', $gitignore);
        $this->assertStringContainsString('/.claude/skills/', $gitignore);
    }

    public function testReinstallIsIdempotent()
    {
        $this->createSkill($this->rootDir.'/vendor/vendor/pkg-a/skills', 'system-information', 'System info.');
        $intent = ['vendor/pkg-a' => ['enabled' => true]];

        $this->installer()->install($this->discover(), $intent);
        $lockAfterFirst = (new SkillLock($this->rootDir))->read();

        $second = $this->installer()->install($this->discover(), $intent);

        $this->assertSame([], $second->installed);
        $this->assertSame([], $second->removed);
        $this->assertSame(['mate-system-information'], $second->active);
        $this->assertSame($lockAfterFirst, (new SkillLock($this->rootDir))->read());
    }

    public function testPruneRemovesSkillWhoseSourceVanished()
    {
        $this->createSkill($this->rootDir.'/vendor/vendor/pkg-a/skills', 'system-information', 'System info.');
        $this->installer()->install($this->discover(), ['vendor/pkg-a' => ['enabled' => true]]);

        $this->removeDirectory($this->rootDir.'/vendor/vendor/pkg-a');

        $result = $this->installer()->install($this->discover(), ['vendor/pkg-a' => ['enabled' => true]]);

        $this->assertSame(['mate-system-information'], $result->removed);
        $this->assertSame([], $result->active);
        $this->assertDirectoryDoesNotExist($this->rootDir.'/.agents/skills/mate-system-information');
        $this->assertFalse(is_link($this->rootDir.'/.claude/skills/mate-system-information'));
        $this->assertSame([], (new SkillLock($this->rootDir))->read());
    }

    public function testDisabledExtensionKeepsSkillOutOfGeneratedFolders()
    {
        $this->createSkill($this->rootDir.'/vendor/vendor/pkg-a/skills', 'system-information', 'System info.');

        $result = $this->installer()->install($this->discover(), ['vendor/pkg-a' => ['enabled' => false]]);

        $this->assertSame([], $result->active);
        $this->assertDirectoryDoesNotExist($this->rootDir.'/.agents/skills/mate-system-information');
    }

    public function testDisabledSkillKeepsItOutOfGeneratedFolders()
    {
        $this->createSkill($this->rootDir.'/vendor/vendor/pkg-a/skills', 'system-information', 'System info.');

        $result = $this->installer()->install($this->discover(), [
            'vendor/pkg-a' => ['enabled' => true, 'skills' => ['system-information' => ['enabled' => false, 'override' => false]]],
        ]);

        $this->assertSame([], $result->active);
        $this->assertDirectoryDoesNotExist($this->rootDir.'/.agents/skills/mate-system-information');
    }

    public function testOverrideBuildsFromUserCopyAndLeavesItUntouched()
    {
        $this->createSkill($this->rootDir.'/vendor/vendor/pkg-a/skills', 'system-information', 'Vendor body.', 'VENDOR CONTENT');
        $this->createSkill($this->rootDir.'/mate/skills', 'system-information', 'Overridden.', 'OVERRIDE CONTENT');

        $result = $this->installer()->install($this->discover(), [
            'vendor/pkg-a' => ['enabled' => true, 'skills' => ['system-information' => ['enabled' => true, 'override' => true]]],
        ]);

        $this->assertSame(['mate-system-information'], $result->active);

        $generated = file_get_contents($this->rootDir.'/.agents/skills/mate-system-information/SKILL.md');
        $this->assertIsString($generated);
        $this->assertStringContainsString('OVERRIDE CONTENT', $generated);
        $this->assertStringNotContainsString('VENDOR CONTENT', $generated);

        // The override source is user-owned and must never be rewritten by the installer.
        $override = file_get_contents($this->rootDir.'/mate/skills/system-information/SKILL.md');
        $this->assertIsString($override);
        $this->assertStringContainsString('name: system-information', $override);

        $lock = (new SkillLock($this->rootDir))->read();
        $this->assertTrue($lock['mate-system-information']['overridden']);
    }

    public function testOverrideWithMissingUserCopyIsSkipped()
    {
        $this->createSkill($this->rootDir.'/vendor/vendor/pkg-a/skills', 'system-information', 'Vendor body.');

        $result = $this->installer()->install($this->discover(), [
            'vendor/pkg-a' => ['enabled' => true, 'skills' => ['system-information' => ['enabled' => true, 'override' => true]]],
        ]);

        $this->assertArrayHasKey('mate-system-information', $result->skipped);
        $this->assertSame([], $result->active);
    }

    /**
     * @return list<DiscoveredSkill>
     */
    private function discover(): array
    {
        $discovery = new SkillDiscovery($this->rootDir, new SkillFrontmatter(), new NullLogger());

        return $discovery->discover([
            'vendor/pkg-a' => ['dirs' => [], 'includes' => [], 'skills' => 'skills'],
        ]);
    }

    private function installer(): SkillInstaller
    {
        return new SkillInstaller(
            $this->rootDir,
            new SkillFrontmatter(),
            new SkillLock($this->rootDir),
            new SkillContentHasher(),
            new NullLogger(),
        );
    }
}
