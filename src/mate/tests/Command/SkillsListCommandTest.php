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
use Symfony\AI\Mate\Command\SkillsListCommand;
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
final class SkillsListCommandTest extends TestCase
{
    use SkillFixtureTrait;

    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/mate-skills-list-cmd-'.uniqid();
        mkdir($this->rootDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testListsNotInstalledSkillBeforeInstall()
    {
        $this->createPackageWithSkill();

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = $tester->getDisplay();
        $this->assertStringContainsString('mate-system-information', $output);
        $this->assertStringContainsString('not installed', $output);
    }

    public function testListsOkStatusAfterInstall()
    {
        $this->createPackageWithSkill();
        $this->install();

        $tester = new CommandTester($this->command());
        $tester->execute([]);

        $this->assertStringContainsString('mate-system-information', $tester->getDisplay());
        $this->assertStringContainsString('ok', $tester->getDisplay());
    }

    public function testJsonFormat()
    {
        $this->createPackageWithSkill();
        $this->install();

        $tester = new CommandTester($this->command());
        $tester->execute(['--format' => 'json']);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded);
        $this->assertSame(1, $decoded['summary']['total']);
        $this->assertSame('mate-system-information', $decoded['skills'][0]['installed_name']);
        $this->assertSame('ok', $decoded['skills'][0]['status']);
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

    private function install(): void
    {
        $logger = new NullLogger();
        $frontmatter = new SkillFrontmatter();
        $discovery = new ComposerExtensionDiscovery($this->rootDir, $logger);
        $extensions = $discovery->discover();
        $extensions['_custom'] = $discovery->discoverRootProject();
        $skills = (new SkillDiscovery($this->rootDir, $frontmatter, $logger))->discover($extensions);
        $installer = new SkillInstaller($this->rootDir, $frontmatter, new SkillLock($this->rootDir), new SkillContentHasher(), $logger);
        $installer->install($skills, []);
    }

    private function command(): SkillsListCommand
    {
        $logger = new NullLogger();
        $frontmatter = new SkillFrontmatter();

        return new SkillsListCommand(
            $this->rootDir,
            new ComposerExtensionDiscovery($this->rootDir, $logger),
            new ExtensionConfigSynchronizer($this->rootDir),
            new SkillDiscovery($this->rootDir, $frontmatter, $logger),
            new SkillLock($this->rootDir),
            new SkillContentHasher(),
        );
    }
}
