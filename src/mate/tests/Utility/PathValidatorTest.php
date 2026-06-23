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
use Symfony\AI\Mate\Utility\PathValidator;

/**
 * Test PathValidator utility class.
 *
 * @author Security Audit
 */
class PathValidatorTest extends TestCase
{
    /**
     * Test isSafePath with valid paths.
     */
    public function testIsSafePathWithValidPaths(): void
    {
        $validPaths = [
            'file.txt',
            'path/to/file.txt',
            'path/to/nested/file.txt',
            'file-name.txt',
            'file_name.txt',
            'file.name.txt',
            '/absolute/path/file.txt',
            'C:\\Windows\\path\\file.txt',
        ];

        foreach ($validPaths as $path) {
            $this->assertTrue(
                PathValidator::isSafePath($path),
                "Path '$path' should be considered safe"
            );
        }
    }

    /**
     * Test isSafePath with path traversal attempts.
     */
    public function testIsSafePathWithPathTraversal(): void
    {
        $unsafePaths = [
            '../file.txt',
            'path/../file.txt',
            'path/../../file.txt',
            '..\\file.txt',
            '%2e%2e/file.txt',
            '%2E%2E/file.txt',
            "file\0.txt",
            "path/\0file.txt",
        ];

        foreach ($unsafePaths as $path) {
            $this->assertFalse(
                PathValidator::isSafePath($path),
                "Path '$path' should be considered unsafe (path traversal)"
            );
        }
    }

    /**
     * Test isWithinDirectory with valid paths.
     */
    public function testIsWithinDirectoryWithValidPaths(): void
    {
        $baseDir = '/var/www/project';

        $validPaths = [
            '/var/www/project/file.txt',
            '/var/www/project/subdir/file.txt',
            '/var/www/project/nested/subdir/file.txt',
        ];

        foreach ($validPaths as $path) {
            $this->assertTrue(
                PathValidator::isWithinDirectory($path, $baseDir),
                "Path '$path' should be within '$baseDir'"
            );
        }
    }

    /**
     * Test isWithinDirectory with paths outside the base directory.
     */
    public function testIsWithinDirectoryWithPathsOutside(): void
    {
        $baseDir = '/var/www/project';

        $invalidPaths = [
            '/var/www/other/file.txt',
            '/etc/passwd',
            '/var/www/project/../../../etc/passwd',
            '/tmp/file.txt',
        ];

        foreach ($invalidPaths as $path) {
            $this->assertFalse(
                PathValidator::isWithinDirectory($path, $baseDir),
                "Path '$path' should NOT be within '$baseDir'"
            );
        }
    }

    /**
     * Test isAbsolutePath.
     */
    public function testIsAbsolutePath(): void
    {
        // Unix absolute paths
        $this->assertTrue(PathValidator::isAbsolutePath('/path/to/file'));
        $this->assertTrue(PathValidator::isAbsolutePath('/'));

        // Unix relative paths
        $this->assertFalse(PathValidator::isAbsolutePath('path/to/file'));
        $this->assertFalse(PathValidator::isAbsolutePath('./path/to/file'));
        $this->assertFalse(PathValidator::isAbsolutePath('../path/to/file'));

        // Windows absolute paths
        $this->assertTrue(PathValidator::isAbsolutePath('C:\\path\\to\\file'));
        $this->assertTrue(PathValidator::isAbsolutePath('\\\\server\\share\\file'));
        $this->assertTrue(PathValidator::isAbsolutePath('\\server\\share\\file'));

        // Windows relative paths
        $this->assertFalse(PathValidator::isAbsolutePath('path\\to\\file'));
    }

    /**
     * Test makeAbsolute.
     */
    public function testMakeAbsolute(): void
    {
        // Absolute path should remain unchanged
        $this->assertEquals(
            '/absolute/path',
            PathValidator::makeAbsolute('/absolute/path')
        );

        // Relative path should be made absolute
        $result = PathValidator::makeAbsolute('relative/path', '/base/dir');
        $this->assertEquals('/base/dir/relative/path', $result);

        // Relative path with leading slash
        $result = PathValidator::makeAbsolute('/relative/path', '/base/dir');
        $this->assertEquals('/relative/path', $result);

        // Relative path with trailing slash in base
        $result = PathValidator::makeAbsolute('relative/path', '/base/dir/');
        $this->assertEquals('/base/dir/relative/path', $result);
    }

    /**
     * Test hasSafeCharacters.
     */
    public function testHasSafeCharacters(): void
    {
        // Safe paths
        $this->assertTrue(PathValidator::hasSafeCharacters('file.txt'));
        $this->assertTrue(PathValidator::hasSafeCharacters('path/to/file.txt'));
        $this->assertTrue(PathValidator::hasSafeCharacters('file-name_123.txt'));

        // Unsafe paths (with special characters)
        $this->assertFalse(PathValidator::hasSafeCharacters('file;rm.txt'));
        $this->assertFalse(PathValidator::hasSafeCharacters('file`whoami`.txt'));
        $this->assertFalse(PathValidator::hasSafeCharacters('file$(cmd).txt'));
    }

    /**
     * Test isRegularFile.
     */
    public function testIsRegularFile(): void
    {
        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, 'test content');

        try {
            $this->assertTrue(PathValidator::isRegularFile($tempFile));

            // Test with directory
            $tempDir = sys_get_temp_dir();
            $this->assertFalse(PathValidator::isRegularFile($tempDir));

            // Test with non-existent file
            $this->assertFalse(PathValidator::isRegularFile('/nonexistent/file.txt'));

            // Create a symlink (if possible)
            if (function_exists('symlink')) {
                $tempDir = sys_get_temp_dir();
                $symlinkPath = $tempDir . '/test_symlink_' . uniqid();
                if (@symlink($tempFile, $symlinkPath)) {
                    try {
                        $this->assertFalse(PathValidator::isRegularFile($symlinkPath));
                    } finally {
                        @unlink($symlinkPath);
                    }
                }
            }
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Test isSafeToInclude.
     */
    public function testIsSafeToInclude(): void
    {
        // Create a temporary file
        $tempFile = tempnam(sys_get_temp_dir(), 'test');
        file_put_contents($tempFile, '<?php return true;');

        try {
            // Safe file
            $this->assertTrue(PathValidator::isSafeToInclude($tempFile));

            // File with path traversal in path
            $this->assertFalse(PathValidator::isSafeToInclude('../../../etc/passwd'));

            // Non-existent file
            $this->assertFalse(PathValidator::isSafeToInclude('/nonexistent/file.txt'));

            // Test with allowed base directory
            $this->assertTrue(PathValidator::isSafeToInclude($tempFile, sys_get_temp_dir()));

            // File outside allowed directory
            $this->assertFalse(PathValidator::isSafeToInclude('/etc/passwd', sys_get_temp_dir()));
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Test isWithinDirectory with symlinks.
     */
    public function testIsWithinDirectoryWithSymlinks(): void
    {
        if (!function_exists('symlink')) {
            $this->markTestSkipped('symlink function not available');
        }

        $tempDir = sys_get_temp_dir();
        $testDir = $tempDir . '/test_dir_' . uniqid();
        $testFile = $testDir . '/test.txt';
        $symlinkPath = $tempDir . '/test_symlink_' . uniqid();

        try {
            mkdir($testDir);
            file_put_contents($testFile, 'test');

            // Create a symlink pointing outside the directory
            if (@symlink('/etc/passwd', $symlinkPath)) {
                // The symlink itself is in temp dir, but points to /etc/passwd
                // isWithinDirectory should check the symlink path, not the target
                $this->assertTrue(
                    PathValidator::isWithinDirectory($symlinkPath, $tempDir),
                    'Symlink path should be considered within temp dir'
                );

                // But if we resolve the symlink, it should be outside
                $resolved = realpath($symlinkPath);
                if ($resolved && file_exists($resolved)) {
                    $this->assertFalse(
                        PathValidator::isWithinDirectory($resolved, $tempDir),
                        'Resolved symlink target should be outside temp dir'
                    );
                }
            }
        } finally {
            @unlink($symlinkPath);
            @unlink($testFile);
            @rmdir($testDir);
        }
    }
}
