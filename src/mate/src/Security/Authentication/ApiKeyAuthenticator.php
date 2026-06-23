<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Security\Authentication;

use Psr\Log\LoggerInterface;

/**
 * API Key Authenticator for MCP Server.
 *
 * This class provides API key-based authentication for MCP server requests.
 * API keys can be configured via environment variables or configuration files.
 *
 * @author Security Audit
 */
final class ApiKeyAuthenticator
{
    private ?string $apiKey;
    private bool $enabled;

    public function __construct(
        ?string $apiKey = null,
        bool $enabled = false,
        private ?LoggerInterface $logger = null,
    ) {
        $this->apiKey = $apiKey;
        $this->enabled = $enabled;
    }

    /**
     * Creates an authenticator from environment variables.
     */
    public static function fromEnvironment(?LoggerInterface $logger = null): self
    {
        $apiKey = $_SERVER['MATE_API_KEY'] ?? null;
        $enabled = isset($_SERVER['MATE_AUTH_ENABLED'])
            ? filter_var($_SERVER['MATE_AUTH_ENABLED'], \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE)
            : false;

        return new self($apiKey, $enabled, $logger);
    }

    /**
     * Checks if authentication is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && null !== $this->apiKey && '' !== trim($this->apiKey);
    }

    /**
     * Authenticates a request with the provided API key.
     *
     * @param string|null $providedKey The API key provided in the request
     */
    public function authenticate(?string $providedKey): bool
    {
        if (!$this->isEnabled()) {
            // Authentication not enabled, allow all requests
            return true;
        }

        if (null === $providedKey || '' === trim($providedKey)) {
            $this->logger?->warning('Authentication failed: No API key provided');
            return false;
        }

        // Use constant-time comparison to prevent timing attacks
        if (!hash_equals($this->apiKey, $providedKey)) {
            $this->logger?->warning('Authentication failed: Invalid API key', [
                'expected_length' => strlen($this->apiKey),
                'provided_length' => strlen($providedKey),
            ]);
            return false;
        }

        $this->logger?->debug('Authentication successful');
        return true;
    }

    /**
     * Validates and sanitizes an API key.
     *
     * @param string $apiKey The API key to validate
     */
    public static function validateApiKey(string $apiKey): bool
    {
        // API key should be at least 32 characters long
        if (strlen($apiKey) < 32) {
            return false;
        }

        // API key should contain only alphanumeric characters and common special characters
        // This prevents injection attacks and ensures valid keys
        if (!preg_match('/^[a-zA-Z0-9\-_.=+\/]+$/', $apiKey)) {
            return false;
        }

        return true;
    }

    /**
     * Generates a secure random API key.
     *
     * @param int $length The length of the API key (default: 64)
     */
    public static function generateApiKey(int $length = 64): string
    {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_.';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    /**
     * Gets the expected API key (for testing purposes).
     */
    public function getExpectedApiKey(): ?string
    {
        return $this->apiKey;
    }
}
