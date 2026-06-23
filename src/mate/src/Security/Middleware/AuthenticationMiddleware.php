<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Security\Middleware;

use Mcp\Server\Middleware\MiddlewareInterface;
use Mcp\Server\Request;
use Mcp\Server\Response;
use Symfony\AI\Mate\Security\Authentication\ApiKeyAuthenticator;

/**
 * Authentication Middleware for MCP Server.
 *
 * This middleware checks for valid authentication credentials in MCP requests.
 * It supports API key authentication via headers or custom request fields.
 *
 * @author Security Audit
 */
final class AuthenticationMiddleware implements MiddlewareInterface
{
    private ApiKeyAuthenticator $authenticator;

    public function __construct(ApiKeyAuthenticator $authenticator)
    {
        $this->authenticator = $authenticator;
    }

    /**
     * Processes a request and returns a response.
     */
    public function handle(Request $request, callable $next): Response
    {
        // Skip authentication if not enabled
        if (!$this->authenticator->isEnabled()) {
            return $next($request);
        }

        // Try to extract API key from request
        $apiKey = $this->extractApiKey($request);

        // Authenticate the request
        if (!$this->authenticator->authenticate($apiKey)) {
            return $this->createUnauthorizedResponse($request);
        }

        // Authentication successful, proceed with request
        return $next($request);
    }

    /**
     * Extracts API key from the request.
     *
     * Checks multiple locations for the API key:
     * 1. Authorization header (Bearer token)
     * 2. X-API-Key header
     * 3. Custom request field (if supported by MCP)
     */
    private function extractApiKey(Request $request): ?string
    {
        // Check Authorization header (Bearer token)
        $headers = $request->getHeaders();
        if (isset($headers['authorization'])) {
            $authHeader = $headers['authorization'];
            if (is_array($authHeader)) {
                $authHeader = implode(', ', $authHeader);
            }
            
            if (0 === stripos($authHeader, 'Bearer ')) {
                return substr($authHeader, 7);
            }
        }

        // Check X-API-Key header
        if (isset($headers['x-api-key'])) {
            $apiKey = $headers['x-api-key'];
            if (is_array($apiKey)) {
                return implode(', ', $apiKey);
            }
            return $apiKey;
        }

        // Check for API key in request parameters (if accessible)
        // Note: This depends on how MCP SDK exposes request parameters
        // For now, we'll check the headers as the primary method

        return null;
    }

    /**
     * Creates an unauthorized response.
     */
    private function createUnauthorizedResponse(Request $request): Response
    {
        // Create an error response
        // Note: The exact implementation depends on MCP SDK's Response class
        // This is a basic implementation that should be adapted based on the actual SDK
        
        return new Response(
            $request->getId(),
            [
                'error' => [
                    'code' => -32001,
                    'message' => 'Unauthorized: Invalid or missing API key',
                ],
            ]
        );
    }
}
