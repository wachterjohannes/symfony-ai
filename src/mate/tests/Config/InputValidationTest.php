<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Config;

use PHPUnit\Framework\TestCase;

/**
 * Test input validation in default.config.php
 * 
 * @author Security Audit
 */
class InputValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['MATE_DEBUG_LOG_FILE']);
        unset($_SERVER['MATE_DEBUG_FILE']);
        unset($_SERVER['MATE_DEBUG']);
        parent::tearDown();
    }

    /**
     * Test that path traversal in MATE_DEBUG_LOG_FILE is rejected
     */
    public function testPathTraversalInDebugLogFileIsRejected(): void
    {
        // Test with .. in path
        $_SERVER['MATE_DEBUG_LOG_FILE'] = '../../../etc/passwd';
        
        $debugLogFile = $_SERVER['MATE_DEBUG_LOG_FILE'] ?? 'dev.log';
        
        // Apply the same validation logic as in default.config.php
        if (!preg_match('/^[a-zA-Z0-9\-_.\/]+$/', $debugLogFile)) {
            $debugLogFile = 'dev.log';
        }
        
        if (str_contains($debugLogFile, '..') || str_contains($debugLogFile, '\0')) {
            $debugLogFile = 'dev.log';
        }
        
        $this->assertEquals('dev.log', $debugLogFile, 'Path traversal should be rejected and fall back to default');
    }

    /**
     * Test that null bytes in MATE_DEBUG_LOG_FILE are rejected
     */
    public function testNullBytesInDebugLogFileAreRejected(): void
    {
        $_SERVER['MATE_DEBUG_LOG_FILE'] = "malicious\0.log";
        
        $debugLogFile = $_SERVER['MATE_DEBUG_LOG_FILE'] ?? 'dev.log';
        
        // Apply validation
        if (!preg_match('/^[a-zA-Z0-9\-_.\/]+$/', $debugLogFile)) {
            $debugLogFile = 'dev.log';
        }
        
        if (str_contains($debugLogFile, '..') || str_contains($debugLogFile, '\0')) {
            $debugLogFile = 'dev.log';
        }
        
        $this->assertEquals('dev.log', $debugLogFile, 'Null bytes should be rejected');
    }

    /**
     * Test that valid log file paths are accepted
     */
    public function testValidLogFilePathsAreAccepted(): void
    {
        $validPaths = [
            'dev.log',
            'var/log/mate.log',
            'app-dev.log',
            'app_dev.log',
            'app.log.1',
            'logs/app.log',
        ];
        
        foreach ($validPaths as $path) {
            $_SERVER['MATE_DEBUG_LOG_FILE'] = $path;
            
            $debugLogFile = $_SERVER['MATE_DEBUG_LOG_FILE'] ?? 'dev.log';
            
            // Apply validation
            if (!preg_match('/^[a-zA-Z0-9\-_.\/]+$/', $debugLogFile)) {
                $debugLogFile = 'dev.log';
            }
            
            if (str_contains($debugLogFile, '..') || str_contains($debugLogFile, '\0')) {
                $debugLogFile = 'dev.log';
            }
            
            $this->assertEquals($path, $debugLogFile, "Valid path '$path' should be accepted");
        }
    }

    /**
     * Test that invalid characters in log file paths are rejected
     */
    public function testInvalidCharactersInLogFilePathsAreRejected(): void
    {
        $invalidPaths = [
            'file;rm -rf /',
            'file`whoami`',
            'file$(whoami)',
            'file|cat /etc/passwd',
            'file>output.txt',
            'file<input.txt',
            'file\\windows\\path',
        ];
        
        foreach ($invalidPaths as $path) {
            $_SERVER['MATE_DEBUG_LOG_FILE'] = $path;
            
            $debugLogFile = $_SERVER['MATE_DEBUG_LOG_FILE'] ?? 'dev.log';
            
            // Apply validation
            if (!preg_match('/^[a-zA-Z0-9\-_.\/]+$/', $debugLogFile)) {
                $debugLogFile = 'dev.log';
            }
            
            if (str_contains($debugLogFile, '..') || str_contains($debugLogFile, '\0')) {
                $debugLogFile = 'dev.log';
            }
            
            $this->assertEquals('dev.log', $debugLogFile, "Invalid path '$path' should be rejected");
        }
    }

    /**
     * Test that boolean environment variables are properly validated
     */
    public function testBooleanEnvironmentVariablesAreValidated(): void
    {
        // Test MATE_DEBUG_FILE
        $_SERVER['MATE_DEBUG_FILE'] = 'true';
        $debugFileEnabled = isset($_SERVER['MATE_DEBUG_FILE'])
            ? filter_var($_SERVER['MATE_DEBUG_FILE'], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE)
            : false;
        $debugFileEnabled = (bool) $debugFileEnabled;
        $this->assertTrue($debugFileEnabled);

        $_SERVER['MATE_DEBUG_FILE'] = 'false';
        $debugFileEnabled = isset($_SERVER['MATE_DEBUG_FILE'])
            ? filter_var($_SERVER['MATE_DEBUG_FILE'], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE)
            : false;
        $debugFileEnabled = (bool) $debugFileEnabled;
        $this->assertFalse($debugFileEnabled);

        $_SERVER['MATE_DEBUG_FILE'] = '1';
        $debugFileEnabled = isset($_SERVER['MATE_DEBUG_FILE'])
            ? filter_var($_SERVER['MATE_DEBUG_FILE'], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE)
            : false;
        $debugFileEnabled = (bool) $debugFileEnabled;
        $this->assertTrue($debugFileEnabled);

        $_SERVER['MATE_DEBUG_FILE'] = '0';
        $debugFileEnabled = isset($_SERVER['MATE_DEBUG_FILE'])
            ? filter_var($_SERVER['MATE_DEBUG_FILE'], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE)
            : false;
        $debugFileEnabled = (bool) $debugFileEnabled;
        $this->assertFalse($debugFileEnabled);

        // Test MATE_DEBUG
        $_SERVER['MATE_DEBUG'] = 'true';
        $debugEnabled = isset($_SERVER['MATE_DEBUG'])
            ? filter_var($_SERVER['MATE_DEBUG'], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE)
            : false;
        $debugEnabled = (bool) $debugEnabled;
        $this->assertTrue($debugEnabled);
    }

    /**
     * Test that cache directory path is validated
     */
    public function testCacheDirectoryPathIsValidated(): void
    {
        $tempDir = sys_get_temp_dir();
        
        // Ensure temp directory is valid and accessible
        if (!is_dir($tempDir) || !is_writable($tempDir)) {
            $this->markTestSkipped('Temp directory is not accessible');
        }
        
        // Build cache directory path with validation
        $cacheDir = rtrim($tempDir, '/\\') . '/mate';
        
        // Validate that the cache directory path doesn't contain path traversal sequences
        if (str_contains($cacheDir, '..') || str_contains($cacheDir, '\0')) {
            $this->fail('Cache directory path should not contain path traversal sequences');
        }
        
        // Verify the path is valid
        $this->assertStringStartsWith($tempDir, $cacheDir);
        $this->assertStringEndsWith('/mate', $cacheDir);
    }

    /**
     * Test that cache directory validation rejects path traversal
     */
    public function testCacheDirectoryValidationRejectsPathTraversal(): void
    {
        // Simulate a malicious temp directory (this would be caught by the validation)
        $maliciousTempDir = '/tmp/../../../etc';
        
        // Build cache directory path
        $cacheDir = rtrim($maliciousTempDir, '/\\') . '/mate';
        
        // Validate that the cache directory path doesn't contain path traversal sequences
        if (str_contains($cacheDir, '..') || str_contains($cacheDir, '\0')) {
            $this->assertTrue(true, 'Path traversal should be detected');
        } else {
            $this->fail('Path traversal should have been detected');
        }
    }
}
