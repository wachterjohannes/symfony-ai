<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Utility;

/**
 * Utility class for validating file paths to prevent security vulnerabilities.
 *
 * @author Security Audit
 */
final class PathValidator
{
    /**
     * Validates that a path does not contain path traversal sequences.
     *
     * This method checks for:
     * - Direct `..` sequences
     * - URL-encoded variants (`%2e%2e`, `%2E%2E`)
     * - Null bytes (`\0`)
     * - Other potential traversal patterns
     */
    public static function isSafePath(string $path): bool
    {
        // Check for direct .. sequences
        if (str_contains($path, '..')) {
            return false;
        }

        // Check for URL-encoded ..
        if (str_contains($path, '%2e%2e') || str_contains($path, '%2E%2E')) {
            return false;
        }

        // Check for null bytes (can truncate paths in some contexts)
        if (str_contains($path, "\0")) {
            return false;
        }

        // Check for other encoding variants
        if (str_contains($path, '%252e')) { // Double-encoded %2e
            return false;
        }

        return true;
    }

    /**
     * Validates that a file path is within a specified directory.
     *
     * This prevents path traversal attacks by ensuring the resolved path
     * is within the allowed base directory.
     *
     * @param string $filePath The file path to validate
     * @param string $baseDir  The base directory that the file should be within
     */
    public static function isWithinDirectory(string $filePath, string $baseDir): bool
    {
        // Normalize paths
        $resolvedFilePath = realpath($filePath) ?: $filePath;
        $resolvedBaseDir = realpath($baseDir) ?: $baseDir;

        // Ensure both paths are absolute
        if (!self::isAbsolutePath($resolvedFilePath)) {
            $resolvedFilePath = self::makeAbsolute($resolvedFilePath, $resolvedBaseDir);
        }

        if (!self::isAbsolutePath($resolvedBaseDir)) {
            $resolvedBaseDir = self::makeAbsolute($resolvedBaseDir);
        }

        // Normalize directory separators
        $resolvedFilePath = str_replace('\\', '/', $resolvedFilePath);
        $resolvedBaseDir = str_replace('\\', '/', $resolvedBaseDir);

        // Ensure base directory ends with a separator
        $resolvedBaseDir = rtrim($resolvedBaseDir, '/') . '/';

        // Check if the file path starts with the base directory
        return 0 === strpos($resolvedFilePath, $resolvedBaseDir);
    }

    /**
     * Checks if a path is absolute.
     */
    public static function isAbsolutePath(string $path): bool
    {
        // Unix: starts with /
        if (0 === strpos($path, '/')) {
            return true;
        }

        // Windows: starts with drive letter (C:), UNC path (\\\), or \\
        if (preg_match('/^[a-zA-Z]:|\\\\\\\\|\\\\/', $path)) {
            return true;
        }

        return false;
    }

    /**
     * Makes a relative path absolute based on a base directory.
     */
    public static function makeAbsolute(string $path, ?string $baseDir = null): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        if (null === $baseDir) {
            $baseDir = getcwd() ?: '/';
        }

        return rtrim($baseDir, '/\\') . '/' . ltrim($path, '/\\');
    }

    /**
     * Validates that a path only contains safe characters.
     *
     * @param string $path The path to validate
     * @param string $allowedChars Regex pattern for allowed characters
     */
    public static function hasSafeCharacters(string $path, string $allowedChars = '/^[a-zA-Z0-9\-_.\/]+$/') : bool
    {
        return (bool) preg_match($allowedChars, $path);
    }

    /**
     * Validates that a file is a regular file (not a symlink, directory, etc.).
     */
    public static function isRegularFile(string $path): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        // Check if it's a symlink
        if (is_link($path)) {
            return false;
        }

        // Check if it's a regular file
        return is_file($path);
    }

    /**
     * Validates that a file path is safe to include/load.
     *
     * This performs multiple checks:
     * 1. Path doesn't contain traversal sequences
     * 2. File exists and is a regular file
     * 3. File is readable
     * 4. File path is within allowed directories (if specified)
     *
     * @param string $filePath The file path to validate
     * @param string|null $allowedBaseDir If specified, the file must be within this directory
     */
    public static function isSafeToInclude(string $filePath, ?string $allowedBaseDir = null): bool
    {
        // Check for path traversal
        if (!self::isSafePath($filePath)) {
            return false;
        }

        // Check if file exists
        if (!file_exists($filePath)) {
            return false;
        }

        // Check if it's a regular file (not a symlink, directory, etc.)
        if (!self::isRegularFile($filePath)) {
            return false;
        }

        // Check if file is readable
        if (!is_readable($filePath)) {
            return false;
        }

        // Check if file is within allowed directory
        if (null !== $allowedBaseDir && !self::isWithinDirectory($filePath, $allowedBaseDir)) {
            return false;
        }

        return true;
    }
}
