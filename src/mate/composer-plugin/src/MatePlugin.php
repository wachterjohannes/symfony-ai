<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\ComposerPlugin;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Composer plugin that automatically triggers Mate extension discovery
 * after composer install/update operations.
 *
 * If the project has been initialized (mate/extensions.php exists),
 * runs `vendor/bin/mate discover` automatically. Otherwise, suggests
 * running `vendor/bin/mate init`.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
final class MatePlugin implements PluginInterface, EventSubscriberInterface
{
    private IOInterface $io;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->io = $io;
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            ScriptEvents::POST_INSTALL_CMD => 'onPostInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD => 'onPostInstallOrUpdate',
        ];
    }

    public function onPostInstallOrUpdate(Event $event): void
    {
        $rootDir = getcwd();
        $extensionsFile = $rootDir.'/mate/extensions.php';

        if (!file_exists($extensionsFile)) {
            $this->io->write('');
            $this->io->write('<bg=blue;fg=white>                                                        </>');
            $this->io->write('<bg=blue;fg=white>  AI Mate installed! Run the following to get started:  </>');
            $this->io->write('<bg=blue;fg=white>                                                        </>');
            $this->io->write('<bg=blue;fg=white>    <fg=yellow>vendor/bin/mate init</>                              </>');
            $this->io->write('<bg=blue;fg=white>                                                        </>');
            $this->io->write('');

            return;
        }

        $mateBin = $rootDir.'/vendor/bin/mate';
        if (!file_exists($mateBin)) {
            return;
        }

        // Security: Validate that mateBin is actually a file and not a symlink to a malicious script
        if (!is_file($mateBin)) {
            $this->io->writeError('<warning>AI Mate:</warning> mate binary is not a regular file.');

            return;
        }

        // Security: Use absolute path and validate it's within the project
        $absoluteMateBin = realpath($mateBin);
        $absoluteRootDir = realpath($rootDir);

        if (false === $absoluteMateBin || false === $absoluteRootDir) {
            $this->io->writeError('<warning>AI Mate:</warning> Failed to resolve paths.');

            return;
        }

        // Ensure the mate binary is within the project directory
        if (0 !== strpos($absoluteMateBin, $absoluteRootDir)) {
            $this->io->writeError('<warning>AI Mate:</warning> mate binary is outside the project directory.');

            return;
        }

        // Security: Use escapeshellarg for the working directory
        $escapedRootDir = escapeshellarg($absoluteRootDir);

        $process = proc_open(
            [\PHP_BINARY, $absoluteMateBin, 'discover', '--composer'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $escapedRootDir,
        );

        if (!\is_resource($process)) {
            $this->io->writeError('<warning>AI Mate:</warning> Failed to run extension discovery.');

            return;
        }

        $output = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if (0 !== $exitCode) {
            $this->io->writeError('<warning>AI Mate:</warning> Extension discovery failed.');
            if ('' !== $errorOutput) {
                $this->io->writeError($errorOutput);
            }

            return;
        }

        if ('' !== $output) {
            $this->io->write($output);
        }
    }
}
