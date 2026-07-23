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
use Symfony\AI\Mate\Command\SkillsInstallCommand;
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
final class SkillsInstallCommandTest extends TestCase
{
    use SkillFixtureTrait;

    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/mate-skills-install-cmd-'.uniqid();
        mkdir($this->rootDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testInstallsDeclaredSkills()
    {
        $this->createPackageWithSkill();

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertDirectoryExists($this->rootDir.'/.agents/skills/mate-system-information');
        $this->assertFileExists($this->rootDir.'/mate/skills.lock.php');

        $output = $tester->getDisplay();
        $this->assertStringContainsString('Installed 1 new skill', $output);
        $this->assertStringContainsString('mate-system-information', $output);
    }

    public function testSecondRunIsIdempotent()
    {
        $this->createPackageWithSkill();

        (new CommandTester($this->command()))->execute([]);

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $output = $tester->getDisplay();
        $this->assertStringNotContainsString('Installed 1 new skill', $output);
        $this->assertStringContainsString('1 skill installed', $output);
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

    private function command(): SkillsInstallCommand
    {
        $logger = new NullLogger();
        $frontmatter = new SkillFrontmatter();

        return new SkillsInstallCommand(
            new ComposerExtensionDiscovery($this->rootDir, $logger),
            new ExtensionConfigSynchronizer($this->rootDir),
            new SkillDiscovery($this->rootDir, $frontmatter, $logger),
            new SkillInstaller($this->rootDir, $frontmatter, new SkillLock($this->rootDir), new SkillContentHasher(), $logger),
        );
    }
}
