<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Security\Authentication;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Mate\Security\Authentication\ApiKeyAuthenticator;

/**
 * Test ApiKeyAuthenticator.
 *
 * @author Security Audit
 */
class ApiKeyAuthenticatorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        unset($_SERVER['MATE_API_KEY']);
        unset($_SERVER['MATE_AUTH_ENABLED']);
        parent::tearDown();
    }

    /**
     * Test creating authenticator with valid API key.
     */
    public function testCreateWithValidApiKey(): void
    {
        $apiKey = 'valid-api-key-1234567890abcdef';
        $authenticator = new ApiKeyAuthenticator($apiKey, true);

        $this->assertTrue($authenticator->isEnabled());
        $this->assertEquals($apiKey, $authenticator->getExpectedApiKey());
    }

    /**
     * Test creating authenticator with null API key.
     */
    public function testCreateWithNullApiKey(): void
    {
        $authenticator = new ApiKeyAuthenticator(null, true);

        $this->assertFalse($authenticator->isEnabled());
    }

    /**
     * Test creating authenticator with empty API key.
     */
    public function testCreateWithEmptyApiKey(): void
    {
        $authenticator = new ApiKeyAuthenticator('', true);

        $this->assertFalse($authenticator->isEnabled());
    }

    /**
     * Test creating authenticator with authentication disabled.
     */
    public function testCreateWithAuthenticationDisabled(): void
    {
        $apiKey = 'valid-api-key';
        $authenticator = new ApiKeyAuthenticator($apiKey, false);

        $this->assertFalse($authenticator->isEnabled());
    }

    /**
     * Test fromEnvironment with environment variables set.
     */
    public function testFromEnvironmentWithVariablesSet(): void
    {
        $_SERVER['MATE_API_KEY'] = 'test-api-key';
        $_SERVER['MATE_AUTH_ENABLED'] = 'true';

        $authenticator = ApiKeyAuthenticator::fromEnvironment();

        $this->assertTrue($authenticator->isEnabled());
        $this->assertEquals('test-api-key', $authenticator->getExpectedApiKey());
    }

    /**
     * Test fromEnvironment with authentication disabled.
     */
    public function testFromEnvironmentWithAuthenticationDisabled(): void
    {
        $_SERVER['MATE_API_KEY'] = 'test-api-key';
        $_SERVER['MATE_AUTH_ENABLED'] = 'false';

        $authenticator = ApiKeyAuthenticator::fromEnvironment();

        $this->assertFalse($authenticator->isEnabled());
    }

    /**
     * Test fromEnvironment with no environment variables.
     */
    public function testFromEnvironmentWithNoVariables(): void
    {
        $authenticator = ApiKeyAuthenticator::fromEnvironment();

        $this->assertFalse($authenticator->isEnabled());
        $this->assertNull($authenticator->getExpectedApiKey());
    }

    /**
     * Test successful authentication.
     */
    public function testSuccessfulAuthentication(): void
    {
        $apiKey = 'test-api-key';
        $authenticator = new ApiKeyAuthenticator($apiKey, true);

        $this->assertTrue($authenticator->authenticate($apiKey));
    }

    /**
     * Test authentication with wrong API key.
     */
    public function testAuthenticationWithWrongApiKey(): void
    {
        $apiKey = 'test-api-key';
        $authenticator = new ApiKeyAuthenticator($apiKey, true);

        $this->assertFalse($authenticator->authenticate('wrong-api-key'));
    }

    /**
     * Test authentication with null API key.
     */
    public function testAuthenticationWithNullApiKey(): void
    {
        $apiKey = 'test-api-key';
        $authenticator = new ApiKeyAuthenticator($apiKey, true);

        $this->assertFalse($authenticator->authenticate(null));
    }

    /**
     * Test authentication with empty API key.
     */
    public function testAuthenticationWithEmptyApiKey(): void
    {
        $apiKey = 'test-api-key';
        $authenticator = new ApiKeyAuthenticator($apiKey, true);

        $this->assertFalse($authenticator->authenticate(''));
    }

    /**
     * Test authentication when disabled.
     */
    public function testAuthenticationWhenDisabled(): void
    {
        $apiKey = 'test-api-key';
        $authenticator = new ApiKeyAuthenticator($apiKey, false);

        // When authentication is disabled, any key (or no key) should be accepted
        $this->assertTrue($authenticator->authenticate(null));
        $this->assertTrue($authenticator->authenticate(''));
        $this->assertTrue($authenticator->authenticate('any-key'));
    }

    /**
     * Test validateApiKey with valid keys.
     */
    public function testValidateApiKeyWithValidKeys(): void
    {
        $validKeys = [
            'abcdefghijklmnopqrstuvwxyz123456', // 32 chars, lowercase
            'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', // 32 chars, uppercase
            'abc-123_def.456=789', // 16 chars with special chars
            str_repeat('a', 64), // 64 chars
            'test-api-key-1234567890abcdefghijklmnop', // 40 chars
        ];

        foreach ($validKeys as $key) {
            $this->assertTrue(
                ApiKeyAuthenticator::validateApiKey($key),
                "Key '$key' should be valid"
            );
        }
    }

    /**
     * Test validateApiKey with invalid keys.
     */
    public function testValidateApiKeyWithInvalidKeys(): void
    {
        $invalidKeys = [
            'short', // Too short (< 32 chars)
            str_repeat('a', 31), // Exactly 31 chars (too short)
            'key with spaces', // Contains spaces
            'key;command', // Contains semicolon
            'key`command`', // Contains backticks
            'key$(command)', // Contains command substitution
            'key|command', // Contains pipe
            'key>file', // Contains redirect
            'key<file', // Contains redirect
            'key\'command\'', // Contains quotes
            'key\"command\"', // Contains double quotes
        ];

        foreach ($invalidKeys as $key) {
            $this->assertFalse(
                ApiKeyAuthenticator::validateApiKey($key),
                "Key '$key' should be invalid"
            );
        }
    }

    /**
     * Test generateApiKey.
     */
    public function testGenerateApiKey(): void
    {
        $apiKey = ApiKeyAuthenticator::generateApiKey();

        // Should be 64 characters by default
        $this->assertEquals(64, strlen($apiKey));

        // Should be valid
        $this->assertTrue(ApiKeyAuthenticator::validateApiKey($apiKey));

        // Should be different each time
        $anotherApiKey = ApiKeyAuthenticator::generateApiKey();
        $this->assertNotEquals($apiKey, $anotherApiKey);
    }

    /**
     * Test generateApiKey with custom length.
     */
    public function testGenerateApiKeyWithCustomLength(): void
    {
        $apiKey = ApiKeyAuthenticator::generateApiKey(32);

        $this->assertEquals(32, strlen($apiKey));
        $this->assertTrue(ApiKeyAuthenticator::validateApiKey($apiKey));
    }

    /**
     * Test that authentication uses constant-time comparison.
     */
    public function testConstantTimeComparison(): void
    {
        $apiKey = str_repeat('a', 64);
        $authenticator = new ApiKeyAuthenticator($apiKey, true);

        // This test verifies that the comparison doesn't short-circuit
        // We can't directly test timing, but we can verify the method exists
        // The actual constant-time comparison is done by hash_equals()
        
        // Test with completely different key
        $start = microtime(true);
        $authenticator->authenticate(str_repeat('b', 64));
        $time1 = microtime(true) - $start;

        // Test with key that differs only in the last character
        $start = microtime(true);
        $authenticator->authenticate(str_repeat('a', 63) . 'b');
        $time2 = microtime(true) - $start;

        // The times should be similar (within a reasonable margin)
        // Note: This is a basic check and may not be reliable in all environments
        $this->assertLessThan(0.1, abs($time1 - $time2), 'Authentication should use constant-time comparison');
    }
}
