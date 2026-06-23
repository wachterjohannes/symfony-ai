<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Utility;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Exception\PidFileException;
use Symfony\AI\Mate\Utility\PidFileManager;

/**
 * Test PidFileManager utility class.
 *
 * @author Security Audit
 */
class PidFileManagerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mate_pid_test_' . uniqid();
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        // Clean up all test files
        $files = glob($this->tempDir . '/*.pid');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /**
     * Test creating a PID file.
     */
    public function testCreatePidFile(): void
    {
        $pidFilePath = $this->tempDir . '/test.pid';
        $pidFileManager = new PidFileManager($pidFilePath);

        try {
            $result = $pidFileManager->create();
            $this->assertTrue($result);
            $this->assertFileExists($pidFilePath);

            $content = file_get_contents($pidFilePath);
            $this->assertEquals((string) getmypid(), trim($content));
        } finally {
            $pidFileManager->cleanup();
        }
    }

    /**
     * Test that duplicate PID file creation fails.
     */
    public function testDuplicatePidFileCreationFails(): void
    {
        $pidFilePath = $this->tempDir . '/test.pid';
        
        // Create first PID file
        $pidFileManager1 = new PidFileManager($pidFilePath);
        $pidFileManager1->create();

        try {
            // Try to create second PID file with same path
            $pidFileManager2 = new PidFileManager($pidFilePath);
            
            // This should throw an exception because the file already exists
            // and the process is still running
            $this->expectException(PidFileException::class);
            $pidFileManager2->create();
        } finally {
            $pidFileManager1->cleanup();
        }
    }

    /**
     * Test reading PID from file.
     */
    public function testReadPid(): void
    {
        $pidFilePath = $this->tempDir . '/test.pid';
        $pidFileManager = new PidFileManager($pidFilePath);

        try {
            $pidFileManager->create();
            
            $pid = $pidFileManager->readPid();
            $this->assertEquals(getmypid(), $pid);
        } finally {
            $pidFileManager->cleanup();
        }
    }

    /**
     * Test reading PID from non-existent file.
     */
    public function testReadPidFromNonExistentFile(): void
    {
        $pidFilePath = $this->tempDir . '/nonexistent.pid';
        $pidFileManager = new PidFileManager($pidFilePath);

        $pid = $pidFileManager->readPid();
        $this->assertNull($pid);
    }

    /**
     * Test validating PID file.
     */
    public function testValidatePidFile(): void
    {
        $pidFilePath = $this->tempDir . '/test.pid';
        $pidFileManager = new PidFileManager($pidFilePath);

        try {
            $pidFileManager->create();
            
            $this->assertTrue($pidFileManager->validate());
        } finally {
            $pidFileManager->cleanup();
        }
    }

    /**
     * Test validating PID file with wrong PID.
     */
    public function testValidatePidFileWithWrongPid(): void
    {
        $pidFilePath = $this->tempDir . '/test.pid';
        
        // Write a different PID to the file
        file_put_contents($pidFilePath, '99999');
        
        $pidFileManager = new PidFileManager($pidFilePath);
        
        $this->assertFalse($pidFileManager->validate());
    }

    /**
     * Test cleanup of PID file.
     */
    public function testCleanupPidFile(): void
    {
        $pidFilePath = $this->tempDir . '/test.pid';
        $pidFileManager = new PidFileManager($pidFilePath);

        $pidFileManager->create();
        $this->assertFileExists($pidFilePath);

        $pidFileManager->cleanup();
        $this->assertFileDoesNotExist($pidFilePath);
    }

    /**
     * Test cleanup only removes own PID file.
     */
    public function testCleanupOnlyRemovesOwnPidFile(): void
    {
        $pidFilePath = $this->tempDir . '/test.pid';
        
        // Create a PID file with a different PID
        file_put_contents($pidFilePath, '99999');
        
        $pidFileManager = new PidFileManager($pidFilePath);
        
        // Cleanup should not remove the file because it doesn't belong to current process
        $pidFileManager->cleanup();
        
        $this->assertFileExists($pidFilePath);
    }

    /**
     * Test cleanup with non-existent file.
     */
    public function testCleanupWithNonExistentFile(): void
    {
        $pidFilePath = $this->tempDir . '/nonexistent.pid';
        $pidFileManager = new PidFileManager($pidFilePath);

        // Should not throw an error
        $pidFileManager->cleanup();
    }

    /**
     * Test isProcessRunning with current process.
     */
    public function testIsProcessRunningWithCurrentProcess(): void
    {
        $pidFileManager = new PidFileManager($this->tempDir . '/test.pid', getmypid());
        
        $this->assertTrue($pidFileManager->isProcessRunning(getmypid()));
    }

    /**
     * Test isProcessRunning with non-existent process.
     */
    public function testIsProcessRunningWithNonExistentProcess(): void
    {
        $pidFileManager = new PidFileManager($this->tempDir . '/test.pid');
        
        // Use a PID that's very unlikely to be running
        $this->assertFalse($pidFileManager->isProcessRunning(999999));
    }

    /**
     * Test isProcessRunning with invalid PID.
     */
    public function testIsProcessRunningWithInvalidPid(): void
    {
        $pidFileManager = new PidFileManager($this->tempDir . '/test.pid');
        
        $this->assertFalse($pidFileManager->isProcessRunning(0));
        $this->assertFalse($pidFileManager->isProcessRunning(-1));
    }

    /**
     * Test handling of stale PID file.
     */
    public function testStalePidFileHandling(): void
    {
        $pidFilePath = $this->tempDir . '/test.pid';
        
        // Create a stale PID file with a non-existent PID
        file_put_contents($pidFilePath, '999999');
        
        $pidFileManager = new PidFileManager($pidFilePath);
        
        try {
            // Should succeed because the stale PID file will be removed and recreated
            $result = $pidFileManager->create();
            $this->assertTrue($result);
            
            $content = file_get_contents($pidFilePath);
            $this->assertEquals((string) getmypid(), trim($content));
        } finally {
            $pidFileManager->cleanup();
        }
    }

    /**
     * Test getPidFilePath.
     */
    public function testGetPidFilePath(): void
    {
        $pidFilePath = $this->tempDir . '/test.pid';
        $pidFileManager = new PidFileManager($pidFilePath);

        $this->assertEquals($pidFilePath, $pidFileManager->getPidFilePath());
    }

    /**
     * Test getPid.
     */
    public function testGetPid(): void
    {
        $pid = getmypid();
        $pidFileManager = new PidFileManager($this->tempDir . '/test.pid', $pid);

        $this->assertEquals($pid, $pidFileManager->getPid());
    }

    /**
     * Test PID file permissions.
     */
    public function testPidFilePermissions(): void
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->markTestSkipped('File permission tests not reliable on Windows');
        }

        $pidFilePath = $this->tempDir . '/test.pid';
        $pidFileManager = new PidFileManager($pidFilePath);

        try {
            $pidFileManager->create();
            
            // Check file permissions (should be 0640)
            $permissions = fileperms($pidFilePath) & 0777;
            $this->assertEquals(0640, $permissions, "PID file should have 0640 permissions");
        } finally {
            $pidFileManager->cleanup();
        }
    }
}
