<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Tests\Discovery;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Mate\Discovery\ExtensionDiscovery;

/**
 * @author Johannes Wachter <johannes@sulu.io>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ExtensionDiscoveryTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__.'/Fixtures';
    }

    public function testDiscoverPackagesWithAiMateConfig()
    {
        $discovery = new ExtensionDiscovery(
            $this->fixturesDir.'/with-ai-mate-config',
            [],
            new NullLogger()
        );

        $extensions = $discovery->discover();

        $this->assertCount(3, $extensions);
        $this->assertArrayHasKey('vendor/package-a', $extensions);
        $this->assertArrayHasKey('vendor/package-b', $extensions);
        $this->assertArrayHasKey('_custom', $extensions);

        // Check package-a structure
        $this->assertArrayHasKey('dirs', $extensions['vendor/package-a']);
        $this->assertArrayHasKey('includes', $extensions['vendor/package-a']);

        $this->assertContains('vendor/vendor/package-a/src', $extensions['vendor/package-a']['dirs']);
    }

    public function testIgnoresPackagesWithoutAiMateConfig()
    {
        $discovery = new ExtensionDiscovery(
            $this->fixturesDir.'/without-ai-mate-config',
            [],
            new NullLogger()
        );

        $extensions = $discovery->discover();

        $this->assertCount(1, $extensions);
        $this->assertArrayHasKey('_custom', $extensions);
    }

    public function testIgnoresPackagesWithoutExtraSection()
    {
        $discovery = new ExtensionDiscovery(
            $this->fixturesDir.'/no-extra-section',
            [],
            new NullLogger()
        );

        $extensions = $discovery->discover();

        $this->assertCount(1, $extensions);
        $this->assertArrayHasKey('_custom', $extensions);
    }

    public function testWhitelistFiltering()
    {
        $enabledExtensions = [
            'vendor/package-a',
        ];

        $discovery = new ExtensionDiscovery(
            $this->fixturesDir.'/with-ai-mate-config',
            $enabledExtensions,
            new NullLogger()
        );

        $extensions = $discovery->discover();

        $this->assertCount(2, $extensions);
        $this->assertArrayHasKey('vendor/package-a', $extensions);
        $this->assertArrayNotHasKey('vendor/package-b', $extensions);
        $this->assertArrayHasKey('_custom', $extensions);
    }

    public function testWhitelistWithMultiplePackages()
    {
        $enabledExtensions = [
            'vendor/package-a',
            'vendor/package-b',
        ];

        $discovery = new ExtensionDiscovery(
            $this->fixturesDir.'/with-ai-mate-config',
            $enabledExtensions,
            new NullLogger()
        );

        $extensions = $discovery->discover();

        $this->assertCount(3, $extensions);
        $this->assertArrayHasKey('vendor/package-a', $extensions);
        $this->assertArrayHasKey('vendor/package-b', $extensions);
        $this->assertArrayHasKey('_custom', $extensions);
    }

    public function testExtractsIncludeFiles()
    {
        $discovery = new ExtensionDiscovery(
            $this->fixturesDir.'/with-includes',
            [],
            new NullLogger()
        );

        $extensions = $discovery->discover();

        $this->assertCount(2, $extensions);
        $this->assertArrayHasKey('vendor/package-with-includes', $extensions);
        $this->assertArrayHasKey('_custom', $extensions);

        $includes = $extensions['vendor/package-with-includes']['includes'];
        $this->assertNotEmpty($includes);
        $this->assertStringContainsString('config/config.php', $includes[0]);
    }

    public function testHandlesMissingInstalledJson()
    {
        $discovery = new ExtensionDiscovery(
            $this->fixturesDir.'/no-installed-json',
            [],
            new NullLogger()
        );

        $extensions = $discovery->discover();

        $this->assertCount(1, $extensions);
        $this->assertArrayHasKey('_custom', $extensions);
    }

    public function testHandlesPackagesWithoutType()
    {
        $discovery = new ExtensionDiscovery(
            $this->fixturesDir.'/mixed-types',
            [],
            new NullLogger()
        );

        $extensions = $discovery->discover();

        // Should discover packages with ai-mate config regardless of type field
        $this->assertGreaterThanOrEqual(1, $extensions);
    }
}
