<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Service;

use Psr\Log\AbstractLogger;
use Symfony\AI\Mate\Exception\FileWriteException;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
class Logger extends AbstractLogger
{
    // Secure file permissions: owner read/write, group read, others nothing
    private const FILE_MODE = 0640;

    // Secure directory permissions: owner read/write/execute, group read/execute, others nothing
    private const DIR_MODE = 0750;

    /**
     * @var array<string, string> Patterns to redact from log messages
     */
    private const REDACT_PATTERNS = [
        '/password\s*[=:]\s*[^\s]+/',
        '/passwd\s*[=:]\s*[^\s]+/',
        '/secret\s*[=:]\s*[^\s]+/',
        '/api[_-]?key\s*[=:]\s*[^\s]+/',
        '/apikey\s*[=:]\s*[^\s]+/',
        '/token\s*[=:]\s*[^\s]+/',
        '/auth\s*[=:]\s*[^\s]+/',
        '/database[_-]?url\s*[=:]\s*[^\s]+/',
        '/db[_-]?url\s*[=:]\s*[^\s]+/',
        '/mysql:\/\/[^\s]+/',
        '/pgsql:\/\/[^\s]+/',
        '/mongodb:\/\/[^\s]+/',
        '/redis:\/\/[^\s]+/',
        '/amqp:\/\/[^\s]+/',
    ];

    /**
     * @var array<string, string> Sensitive context keys that should be redacted
     */
    private const SENSITIVE_KEYS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'api-key',
        'auth',
        'authorization',
        'credential',
        'credentials',
        'private_key',
        'privatekey',
        'access_key',
        'accesskey',
        'access-key',
    ];

    public function __construct(
        private string $logFile = 'dev.log',
        private bool $fileLogEnabled = false,
        private bool $debugEnabled = false,
    ) {
    }

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        if (!$this->debugEnabled && 'debug' === $level) {
            return;
        }

        $levelString = match (true) {
            $level instanceof \Stringable => (string) $level,
            \is_string($level) => $level,
            default => 'unknown',
        };

        // Sanitize message to redact sensitive information
        $sanitizedMessage = $this->sanitizeMessage($message);

        // Sanitize context to redact sensitive information
        $sanitizedContext = $this->sanitizeContext($context);

        $logMessage = \sprintf(
            "[%s] %s %s\n",
            strtoupper($levelString),
            $sanitizedMessage,
            ([] === $sanitizedContext || !$this->debugEnabled) ? '' : json_encode($sanitizedContext),
        );

        if ($this->fileLogEnabled || !\defined('STDERR')) {
            $this->ensureDirectoryExists($this->logFile);

            $result = @file_put_contents($this->logFile, $logMessage, \FILE_APPEND);
            if (false === $result) {
                $errorMessage = \sprintf('Failed to write to log file: "%s"', $this->logFile);
                // Fallback to stderr to ensure message is not lost
                if (\defined('STDERR')) {
                    fwrite(\STDERR, "[ERROR] {$errorMessage}\n");
                    fwrite(\STDERR, $logMessage);
                }

                throw new FileWriteException($errorMessage);
            }

            // Ensure the log file has secure permissions
            @chmod($this->logFile, self::FILE_MODE);
        } else {
            fwrite(\STDERR, $logMessage);
        }
    }

    /**
     * Sanitize a log message to redact sensitive information.
     */
    private function sanitizeMessage(\Stringable|string $message): string
    {
        $messageString = (string) $message;

        foreach (self::REDACT_PATTERNS as $pattern) {
            $messageString = preg_replace($pattern, '$1[REDACTED]', $messageString);
        }

        return $messageString;
    }

    /**
     * Sanitize context data to redact sensitive information.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            // Redact sensitive keys
            if ($this->isSensitiveKey($key)) {
                $sanitized[$key] = '[REDACTED]';
                continue;
            }

            $sanitized[$key] = $this->sanitizeValue($value);
        }

        return $sanitized;
    }

    /**
     * Check if a context key is sensitive and should be redacted.
     */
    private function isSensitiveKey(string $key): bool
    {
        $lowerKey = strtolower($key);

        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if (str_contains($lowerKey, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize context data to be JSON-serializable and sanitize sensitive data.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        $normalized = [];

        foreach ($context as $key => $value) {
            $normalized[$key] = $this->normalizeValue($value);
        }

        return $normalized;
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof \Throwable) {
            return [
                'class' => $value::class,
                'message' => $this->sanitizeMessage($value->getMessage()),
                'code' => $value->getCode(),
                'file' => $value->getFile(),
                'line' => $value->getLine(),
                // Only include trace in debug mode, but sanitize it first
                'trace' => $this->debugEnabled ? $this->sanitizeTrace($value->getTraceAsString()) : null,
            ];
        }

        if (\is_array($value)) {
            return array_map(fn ($item) => $this->normalizeValue($item), $value);
        }

        if (\is_object($value)) {
            if (method_exists($value, '__toString')) {
                return $this->sanitizeMessage((string) $value);
            }

            return ['object' => $value::class];
        }

        return $value;
    }

    /**
     * Sanitize a stack trace to redact sensitive information.
     */
    private function sanitizeTrace(string $trace): string
    {
        foreach (self::REDACT_PATTERNS as $pattern) {
            $trace = preg_replace($pattern, '$1[REDACTED]', $trace);
        }

        return $trace;
    }

    private function ensureDirectoryExists(string $filePath): void
    {
        $directory = \dirname($filePath);

        if (!is_dir($directory)) {
            $oldUmask = umask(0027); // Restrict group and others to read/execute only
            if (!@mkdir($directory, self::DIR_MODE, true) && !is_dir($directory)) {
                umask($oldUmask);
                throw new FileWriteException(\sprintf('Failed to create log directory: "%s"', $directory));
            }
            umask($oldUmask);
            @chmod($directory, self::DIR_MODE);
        }
    }
}
