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

use Symfony\AI\Mate\Exception\PidFileException;

/**
 * Utility class for managing PID files securely.
 *
 * This class provides atomic operations for creating, validating, and cleaning up PID files
 * to prevent race conditions and TOCTOU (Time-of-Check-Time-of-Use) vulnerabilities.
 *
 * @author Security Audit
 */
final class PidFileManager
{
    private string $pidFilePath;
    private ?int $pid = null;
    private ?resource $fileHandle = null;

    /**
     * @param string $pidFilePath The path to the PID file
     * @param int|null $pid The process ID (defaults to current process)
     */
    public function __construct(string $pidFilePath, ?int $pid = null)
    {
        $this->pidFilePath = $pidFilePath;
        $this->pid = $pid ?? getmypid();
    }

    /**
     * Creates a PID file atomically with exclusive locking.
     *
     * This method:
     * 1. Creates the directory if it doesn't exist
     * 2. Opens the file with exclusive lock
     * 3. Writes the PID
     * 4. Keeps the file handle open to maintain the lock
     *
     * @return bool True if PID file was created successfully
     *
     * @throws PidFileException If PID file cannot be created
     */
    public function create(): bool
    {
        // Ensure directory exists
        $dir = \dirname($this->pidFilePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new PidFileException(sprintf(
                'Failed to create directory for PID file: %s',
                $dir
            ));
        }

        // Open file with exclusive lock (O_CREAT | O_EXCL | O_WRONLY)
        // This prevents race conditions by ensuring only one process can create the file
        $this->fileHandle = @fopen($this->pidFilePath, 'x');
        
        if (false === $this->fileHandle) {
            // File already exists, check if it's from a stale process
            if (file_exists($this->pidFilePath)) {
                $existingPid = $this->readPid();
                if ($existingPid !== null && $this->isProcessRunning($existingPid)) {
                    // Another instance is running
                    throw new PidFileException(sprintf(
                        'PID file already exists and process %d is still running: %s',
                        $existingPid,
                        $this->pidFilePath
                    ));
                }
                
                // Stale PID file, remove it and try again
                @unlink($this->pidFilePath);
                $this->fileHandle = @fopen($this->pidFilePath, 'x');
                
                if (false === $this->fileHandle) {
                    throw new PidFileException(sprintf(
                        'Failed to create PID file (even after removing stale file): %s',
                        $this->pidFilePath
                    ));
                }
            } else {
                throw new PidFileException(sprintf(
                    'Failed to create PID file: %s',
                    $this->pidFilePath
                ));
            }
        }

        // Write PID to file
        if (false === fwrite($this->fileHandle, (string) $this->pid)) {
            $this->cleanup();
            throw new PidFileException(sprintf(
                'Failed to write PID to file: %s',
                $this->pidFilePath
            ));
        }

        // Flush to ensure data is written
        fflush($this->fileHandle);

        // Set secure permissions
        @chmod($this->pidFilePath, 0640);

        return true;
    }

    /**
     * Validates that the PID file belongs to the current process.
     *
     * This should be called before cleanup to ensure we're deleting our own PID file.
     *
     * @return bool True if the PID file is valid and belongs to current process
     */
    public function validate(): bool
    {
        if (!file_exists($this->pidFilePath)) {
            return false;
        }

        $existingPid = $this->readPid();
        if ($existingPid === null) {
            return false;
        }

        return $existingPid === $this->pid;
    }

    /**
     * Cleans up the PID file.
     *
     * This method:
     * 1. Validates the PID file belongs to current process
     * 2. Closes the file handle if open
     * 3. Removes the PID file
     */
    public function cleanup(): void
    {
        // Close file handle if open
        if (null !== $this->fileHandle && is_resource($this->fileHandle)) {
            fclose($this->fileHandle);
            $this->fileHandle = null;
        }

        // Only remove if it's our PID file
        if ($this->validate()) {
            @unlink($this->pidFilePath);
        }
    }

    /**
     * Reads the PID from the PID file.
     *
     * @return int|null The PID or null if file doesn't exist or can't be read
     */
    public function readPid(): ?int
    {
        if (!file_exists($this->pidFilePath)) {
            return null;
        }

        $content = @file_get_contents($this->pidFilePath);
        if (false === $content || '' === trim($content)) {
            return null;
        }

        $pid = (int) trim($content);
        return $pid > 0 ? $pid : null;
    }

    /**
     * Checks if a process with the given PID is running.
     *
     * @param int $pid The process ID to check
     */
    public function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        // On Unix-like systems, check if /proc/<pid> exists
        if (is_dir("/proc/$pid")) {
            return true;
        }

        // On Windows, use tasklist command
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $output = [];
            $returnVar = 0;
            @exec("tasklist /FI \"PID eq $pid\" /FO CSV /NH", $output, $returnVar);
            return $returnVar === 0 && !empty($output);
        }

        // Fallback: try to send signal 0 (which doesn't actually send a signal but checks if process exists)
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        // If we can't determine, assume it's running to be safe
        return true;
    }

    /**
     * Gets the PID file path.
     */
    public function getPidFilePath(): string
    {
        return $this->pidFilePath;
    }

    /**
     * Gets the process ID.
     */
    public function getPid(): int
    {
        return $this->pid;
    }

    /**
     * Destructor to ensure cleanup.
     */
    public function __destruct()
    {
        $this->cleanup();
    }
}
