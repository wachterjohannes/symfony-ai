<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Command;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Mate\Command\SkillsOverrideCommand;
use Symfony\AI\Mate\Command\SkillsResetCommand;
use Symfony\AI\Mate\Discovery\ComposerExtensionDiscovery;
use Symfony\AI\Mate\Service\ExtensionConfigSynchronizer;
use Symfony\AI\Mate\Skill\SkillContentHasher;
use Symfony\AI\Mate\Skill\SkillDiscovery;
use Symfony\AI\Mate\Skill\SkillFrontmatter;
use Symfony\AI\Mate\Skill\SkillInstaller;
use Symfony\AI\Mate\Skill\SkillLock;
use Symfony\AI\Mate\Skill\SkillOverrideManager;
use Symfony\AI\Mate\Tests\Skill\SkillFixtureTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillsOwnershipCommandTest extends TestCase
{
    use SkillFixtureTrait;

    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/mate-skills-ownership-cmd-'.uniqid();
        mkdir($this->rootDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testOverrideMaterializesEditableCopyAndInstallLeavesItUntouched()
    {
        $this->createPackageWithSkill('VENDOR BODY');

        $tester = new CommandTester(new SkillsOverrideCommand(...$this->deps()));
        $tester->execute(['name' => 'mate-system-information']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFileExists($this->rootDir.'/mate/skills/system-information/SKILL.md');

        $extensions = include $this->rootDir.'/mate/extensions.php';
        $this->assertTrue($extensions['vendor/pkg-a']['skills']['system-information']['override']);
        $this->assertTrue($extensions['vendor/pkg-a']['skills']['system-information']['enabled']);

        // The generated copy is built from the override source.
        $generated = file_get_contents($this->rootDir.'/.agents/skills/mate-system-information/SKILL.md');
        $this->assertIsString($generated);
        $this->assertStringContainsString('VENDOR BODY', $generated);

        // Edit the override, re-run install: the user copy must be left untouched.
        file_put_contents($this->rootDir.'/mate/skills/system-information/SKILL.md', "---\nname: system-information\ndescription: Edited.\n---\nEDITED BODY\n");
        $this->installer()->install($this->discover(), $this->synchronizer()->read());

        $override = file_get_contents($this->rootDir.'/mate/skills/system-information/SKILL.md');
        $this->assertIsString($override);
        $this->assertStringContainsString('EDITED BODY', $override);

        $generatedAfter = file_get_contents($this->rootDir.'/.agents/skills/mate-system-information/SKILL.md');
        $this->assertIsString($generatedAfter);
        $this->assertStringContainsString('EDITED BODY', $generatedAfter);
    }

    public function testOverrideIsIdempotent()
    {
        $this->createPackageWithSkill();

        (new CommandTester(new SkillsOverrideCommand(...$this->deps())))->execute(['name' => 'mate-system-information']);
        file_put_contents($this->rootDir.'/mate/skills/system-information/SKILL.md', "---\nname: system-information\ndescription: Edited.\n---\nEDITED BODY\n");

        $tester = new CommandTester(new SkillsOverrideCommand(...$this->deps()));
        $tester->execute(['name' => 'mate-system-information']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('already overridden', $tester->getDisplay());

        // The idempotent re-run must not clobber the user's edit.
        $override = file_get_contents($this->rootDir.'/mate/skills/system-information/SKILL.md');
        $this->assertIsString($override);
        $this->assertStringContainsString('EDITED BODY', $override);
    }

    public function testResetWithoutForceRefusesAndKeepsCopy()
    {
        $this->createPackageWithSkill();
        (new CommandTester(new SkillsOverrideCommand(...$this->deps())))->execute(['name' => 'mate-system-information']);

        $tester = new CommandTester(new SkillsResetCommand(...$this->deps()));
        $tester->execute(['name' => 'mate-system-information'], ['interactive' => false]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('mate/skills/system-information', $tester->getDisplay());
        $this->assertFileExists($this->rootDir.'/mate/skills/system-information/SKILL.md');
    }

    public function testResetWithForceRemovesCopyAndReturnsToManaged()
    {
        $this->createPackageWithSkill();
        (new CommandTester(new SkillsOverrideCommand(...$this->deps())))->execute(['name' => 'mate-system-information']);
        $this->assertDirectoryExists($this->rootDir.'/mate/skills/system-information');

        $tester = new CommandTester(new SkillsResetCommand(...$this->deps()));
        $tester->execute(['name' => 'mate-system-information', '--force' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertDirectoryDoesNotExist($this->rootDir.'/mate/skills/system-information');

        $extensions = include $this->rootDir.'/mate/extensions.php';
        $this->assertFalse($extensions['vendor/pkg-a']['skills']['system-information']['override']);

        // Still installed, now from the managed source.
        $this->assertDirectoryExists($this->rootDir.'/.agents/skills/mate-system-information');
    }

    public function testResetUnknownSkillFails()
    {
        $this->createPackageWithSkill();

        $tester = new CommandTester(new SkillsResetCommand(...$this->deps()));
        $tester->execute(['name' => 'mate-nope', '--force' => true]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown skill', $tester->getDisplay());
    }

    /**
     * @return array{ComposerExtensionDiscovery, ExtensionConfigSynchronizer, SkillDiscovery, SkillOverrideManager, SkillInstaller}
     */
    private function deps(): array
    {
        $logger = new NullLogger();
        $frontmatter = new SkillFrontmatter();

        return [
            new ComposerExtensionDiscovery($this->rootDir, $logger),
            $this->synchronizer(),
            new SkillDiscovery($this->rootDir, $frontmatter, $logger),
            new SkillOverrideManager($this->rootDir),
            $this->installer(),
        ];
    }

    private function synchronizer(): ExtensionConfigSynchronizer
    {
        return new ExtensionConfigSynchronizer($this->rootDir);
    }

    private function installer(): SkillInstaller
    {
        $logger = new NullLogger();

        return new SkillInstaller($this->rootDir, new SkillFrontmatter(), new SkillLock($this->rootDir), new SkillContentHasher(), $logger);
    }

    /**
     * @return list<\Symfony\AI\Mate\Skill\Model\DiscoveredSkill>
     */
    private function discover(): array
    {
        $logger = new NullLogger();
        $discovery = new ComposerExtensionDiscovery($this->rootDir, $logger);
        $extensions = $discovery->discover();
        $extensions['_custom'] = $discovery->discoverRootProject();

        return (new SkillDiscovery($this->rootDir, new SkillFrontmatter(), $logger))->discover($extensions);
    }

    private function createPackageWithSkill(string $body = 'Body.'): void
    {
        mkdir($this->rootDir.'/vendor/composer', 0777, true);
        file_put_contents($this->rootDir.'/vendor/composer/installed.json', json_encode([
            'packages' => [
                [
                    'name' => 'vendor/pkg-a',
                    'type' => 'library',
                    'extra' => ['ai-mate' => ['skills' => 'skills']],
                ],
            ],
        ]));

        $this->createSkill($this->rootDir.'/vendor/vendor/pkg-a/skills', 'system-information', 'System info.', $body);
    }
}
