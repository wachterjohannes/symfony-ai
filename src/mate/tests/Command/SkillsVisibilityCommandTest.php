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
use Symfony\AI\Mate\Command\SkillsDisableCommand;
use Symfony\AI\Mate\Command\SkillsEnableCommand;
use Symfony\AI\Mate\Discovery\ComposerExtensionDiscovery;
use Symfony\AI\Mate\Service\ExtensionConfigSynchronizer;
use Symfony\AI\Mate\Skill\SkillContentHasher;
use Symfony\AI\Mate\Skill\SkillDiscovery;
use Symfony\AI\Mate\Skill\SkillFrontmatter;
use Symfony\AI\Mate\Skill\SkillInstaller;
use Symfony\AI\Mate\Skill\SkillLock;
use Symfony\AI\Mate\Tests\Skill\SkillFixtureTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillsVisibilityCommandTest extends TestCase
{
    use SkillFixtureTrait;

    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/mate-skills-visibility-cmd-'.uniqid();
        mkdir($this->rootDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testDisableHidesSkillButKeepsIntent()
    {
        $this->createPackageWithSkill();
        $this->install();
        $this->assertDirectoryExists($this->rootDir.'/.agents/skills/mate-system-information');

        $tester = new CommandTester(new SkillsDisableCommand(...$this->deps()));
        $tester->execute(['name' => 'mate-system-information']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertDirectoryDoesNotExist($this->rootDir.'/.agents/skills/mate-system-information');

        $extensions = include $this->rootDir.'/mate/extensions.php';
        $this->assertFalse($extensions['vendor/pkg-a']['skills']['system-information']['enabled']);
    }

    public function testEnableRestoresSkill()
    {
        $this->createPackageWithSkill();
        $this->install();

        (new CommandTester(new SkillsDisableCommand(...$this->deps())))->execute(['name' => 'mate-system-information']);
        $this->assertDirectoryDoesNotExist($this->rootDir.'/.agents/skills/mate-system-information');

        $tester = new CommandTester(new SkillsEnableCommand(...$this->deps()));
        $tester->execute(['name' => 'mate-system-information']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertDirectoryExists($this->rootDir.'/.agents/skills/mate-system-information');

        $extensions = include $this->rootDir.'/mate/extensions.php';
        $this->assertTrue($extensions['vendor/pkg-a']['skills']['system-information']['enabled']);
    }

    public function testUnknownSkillFails()
    {
        $this->createPackageWithSkill();

        $tester = new CommandTester(new SkillsDisableCommand(...$this->deps()));
        $tester->execute(['name' => 'mate-does-not-exist']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unknown skill', $tester->getDisplay());
    }

    /**
     * @return array{ComposerExtensionDiscovery, ExtensionConfigSynchronizer, SkillDiscovery, SkillInstaller}
     */
    private function deps(): array
    {
        $logger = new NullLogger();
        $frontmatter = new SkillFrontmatter();

        return [
            new ComposerExtensionDiscovery($this->rootDir, $logger),
            new ExtensionConfigSynchronizer($this->rootDir),
            new SkillDiscovery($this->rootDir, $frontmatter, $logger),
            new SkillInstaller($this->rootDir, $frontmatter, new SkillLock($this->rootDir), new SkillContentHasher(), $logger),
        ];
    }

    private function install(): void
    {
        $logger = new NullLogger();
        $frontmatter = new SkillFrontmatter();
        $discovery = new ComposerExtensionDiscovery($this->rootDir, $logger);
        $extensions = $discovery->discover();
        $extensions['_custom'] = $discovery->discoverRootProject();
        $skills = (new SkillDiscovery($this->rootDir, $frontmatter, $logger))->discover($extensions);
        (new SkillInstaller($this->rootDir, $frontmatter, new SkillLock($this->rootDir), new SkillContentHasher(), $logger))->install($skills, []);
    }

    private function createPackageWithSkill(): void
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

        $this->createSkill($this->rootDir.'/vendor/vendor/pkg-a/skills', 'system-information', 'System info.');
    }
}
