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
use Symfony\AI\Mate\Skill\SkillLock;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class SkillLockTest extends TestCase
{
    use SkillFixtureTrait;

    private string $rootDir;

    protected function setUp(): void
    {
        $this->rootDir = sys_get_temp_dir().'/mate-skill-lock-'.uniqid();
        mkdir($this->rootDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->rootDir);
    }

    public function testReadReturnsEmptyArrayWhenMissing()
    {
        $lock = new SkillLock($this->rootDir);

        $this->assertFalse($lock->exists());
        $this->assertSame([], $lock->read());
    }

    public function testWriteAndReadRoundTrip()
    {
        $lock = new SkillLock($this->rootDir);

        $entries = [
            'mate-system-information' => [
                'package' => 'vendor/pkg-a',
                'original_name' => 'system-information',
                'source' => 'skills/system-information',
                'strategy' => 'copy',
                'content_hash' => 'sha256:abc',
                'targets' => ['.agents/skills/mate-system-information', '.claude/skills/mate-system-information'],
                'overridden' => false,
            ],
        ];

        $lock->write($entries);

        $this->assertTrue($lock->exists());
        $this->assertSame($entries, $lock->read());
    }

    public function testMaliciousNamesCannotInjectCode()
    {
        $lock = new SkillLock($this->rootDir);

        $maliciousName = "evil', 'injected' => ['enabled' => true], 'x";
        $lock->write([
            $maliciousName => [
                'package' => 'vendor/pkg-a',
                'original_name' => 'system-information',
                'source' => 'skills/system-information',
                'strategy' => 'copy',
                'content_hash' => 'sha256:abc',
                'targets' => [],
                'overridden' => false,
            ],
        ]);

        $read = $lock->read();

        $this->assertArrayHasKey($maliciousName, $read);
        $this->assertArrayNotHasKey('injected', $read);
    }

    public function testDropsMalformedEntries()
    {
        mkdir($this->rootDir.'/mate', 0777, true);
        file_put_contents($this->rootDir.'/mate/skills.lock.php', "<?php\n\nreturn ['broken' => ['package' => 'p']];\n");

        $lock = new SkillLock($this->rootDir);

        $this->assertSame([], $lock->read());
    }
}
