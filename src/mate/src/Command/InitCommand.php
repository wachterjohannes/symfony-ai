<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\AI\Mate\Command;

use HelgeSverre\Toon\Toon;
use Symfony\AI\Mate\Agent\AgentInstructionsMaterializer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Add some config in the project root and scaffold integration helpers.
 * Basically do every thing you need to set things up.
 *
 * @author Johannes Wachter <johannes@sulu.io>
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
#[AsCommand('init', 'Initialize Mate configuration and directory structure')]
class InitCommand extends Command
{
    // Secure file permissions: owner read/write, group read, others nothing
    private const FILE_MODE = 0640;

    // Secure directory permissions: owner read/write/execute, group read/execute, others nothing
    private const DIR_MODE = 0750;

    public function __construct(
        private string $rootDir,
        private AgentInstructionsMaterializer $instructionsMaterializer,
    ) {
        parent::__construct(self::getDefaultName());
    }

    public static function getDefaultName(): string
    {
        return 'init';
    }

    public static function getDefaultDescription(): string
    {
        return 'Initialize Mate configuration and directory structure';
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('AI Mate Initialization');
        $io->text('Setting up AI Mate configuration and directory structure...');
        $io->newLine();

        $actions = [];

        $mateDir = $this->rootDir.'/mate';
        if (!is_dir($mateDir)) {
            $this->mkdirSecure($mateDir, true);
            $actions[] = ['\u2713', 'Created', 'mate/ directory'];
        }

        $files = [
            'mate/extensions.php',
            'mate/config.php',
            'mate/.env',
            'mate/.gitignore',
            'mate/AGENT_INSTRUCTIONS.md',
            'mcp.json',
            'bin/codex',
            'bin/codex.bat',
        ];
        foreach ($files as $file) {
            $fullPath = $this->rootDir.'/'.$file;
            if (!file_exists($fullPath)) {
                $this->copyTemplate($file, $fullPath);
                $this->postCopyTemplateAction($file, $fullPath);
                $actions[] = ['\u2713', 'Created', $file];
            } elseif ($io->confirm(\sprintf('<question>%s already exists. Overwrite?</question>', $fullPath), false)) {
                unlink($fullPath);
                $this->copyTemplate($file, $fullPath);
                $this->postCopyTemplateAction($file, $fullPath);
                $actions[] = ['\u2713', 'Updated', $file];
            } else {
                $actions[] = ['\u25cb', 'Skipped', $file.' (already exists)'];
            }
        }

        // Create symlink from .mcp.json to mcp.json for compatibility
        $mcpJsonPath = $this->rootDir.'/mcp.json';
        $mcpJsonSymlink = $this->rootDir.'/.mcp.json';
        if (file_exists($mcpJsonPath)) {
            if (is_link($mcpJsonSymlink)) {
                unlink($mcpJsonSymlink);
            }
            if (!file_exists($mcpJsonSymlink)) {
                symlink('mcp.json', $mcpJsonSymlink);
                $actions[] = ['\u2713', 'Created', '.mcp.json (symlink to mcp.json)'];
            } elseif ($io->confirm(\sprintf('<question>%s already exists. Replace with symlink?</question>', $mcpJsonSymlink), false)) {
                unlink($mcpJsonSymlink);
                symlink('mcp.json', $mcpJsonSymlink);
                $actions[] = ['\u2713', 'Updated', '.mcp.json (symlink to mcp.json)'];
            } else {
                $actions[] = ['\u25cb', 'Skipped', '.mcp.json (already exists)'];
            }
        }

        $mateSrcDir = $this->rootDir.'/mate/src';
        if (!is_dir($mateSrcDir)) {
            $this->mkdirSecure($mateSrcDir, true);
            file_put_contents($mateSrcDir.'/.gitignore', '');
            // Ensure the .gitignore file has secure permissions
            chmod($mateSrcDir.'/.gitignore', self::FILE_MODE);
            $actions[] = ['\u2713', 'Created', 'mate/src/ directory (for custom MCP tools)'];
        } else {
            $actions[] = ['\u25cb', 'Exists', 'mate/src/ directory'];
        }

        $composerActions = $this->updateComposerJson();
        $actions = array_merge($actions, $composerActions);

        $materializationResult = $this->instructionsMaterializer->synchronizeFromCurrentInstructionsFile();
        if ($materializationResult['agents_file_updated']) {
            $actions[] = ['\u2713', 'Updated', 'AGENTS.md (AI Mate managed instructions block)'];
        } else {
            $actions[] = ['\u26a0', 'Warning', 'Could not update AGENTS.md managed instructions block'];
        }

        $io->section('Summary');
        $io->table(['', 'Action', 'Item'], $actions);

        $io->success('AI Mate initialization complete!');

        $io->comment([
            'Next steps:',
            '  1. Run "composer dump-autoload" to register the Mate\\ autoloader',
            '  2. Add custom MCP tools/resources/prompts to mate/src/',
            '  3. Run your preferred coding agent (e.g. Claude Code) \u2014 it picks up the generated mcp.json; for Codex, use "./bin/codex"',
        ]);

        if (!class_exists(Toon::class)) {
            $io->note('Tip: install "helgesverre/toon" (composer require helgesverre/toon) to reduce tokens in tool responses.');
        }

        return Command::SUCCESS;
    }

    private function copyTemplate(string $template, string $destination): void
    {
        $directory = \dirname($destination);
        if (!is_dir($directory)) {
            $this->mkdirSecure($directory, true);
        }

        copy(__DIR__.'/../../resources/'.$template, $destination);

        // Ensure the copied file has secure permissions
        chmod($destination, self::FILE_MODE);
    }

    private function postCopyTemplateAction(string $template, string $destination): void
    {
        if ('bin/codex' !== $template) {
            // Ensure all files have secure permissions by default
            chmod($destination, self::FILE_MODE);

            return;
        }

        // Executable files need execute permission
        chmod($destination, 0750);
    }

    /**
     * Create a directory with secure permissions.
     *
     * @param bool $recursive Whether to create parent directories
     */
    private function mkdirSecure(string $path, bool $recursive = false): bool
    {
        if (!is_dir($path)) {
            $oldUmask = umask(0027); // Restrict group and others to read/execute only
            $result = mkdir($path, self::DIR_MODE, $recursive);
            umask($oldUmask);

            if ($result) {
                chmod($path, self::DIR_MODE);
            }

            return $result;
        }

        return true;
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function updateComposerJson(): array
    {
        $composerJsonPath = $this->rootDir.'/composer.json';
        $actions = [];

        if (!file_exists($composerJsonPath)) {
            $actions[] = ['\u26a0', 'Warning', 'composer.json not found in project root'];

            return $actions;
        }

        $composerContent = file_get_contents($composerJsonPath);
        if (false === $composerContent) {
            $actions[] = ['\u2717', 'Error', 'Failed to read composer.json'];

            return $actions;
        }

        $composerJson = json_decode($composerContent, true);

        if (\JSON_ERROR_NONE !== json_last_error() || !\is_array($composerJson)) {
            $actions[] = ['\u2717', 'Error', 'Failed to parse composer.json: '.json_last_error_msg()];

            return $actions;
        }

        $modified = false;

        $composerJson['extra'] = \is_array($composerJson['extra'] ?? null) ? $composerJson['extra'] : [];
        $composerJson['autoload-dev'] = \is_array($composerJson['autoload-dev'] ?? null) ? $composerJson['autoload-dev'] : [];
        $composerJson['autoload-dev']['psr-4'] = \is_array($composerJson['autoload-dev']['psr-4'] ?? null) ? $composerJson['autoload-dev']['psr-4'] : [];

        if (!isset($composerJson['extra']['ai-mate'])) {
            $composerJson['extra']['ai-mate'] = [
                'extension' => false,
                'scan-dirs' => ['mate/src'],
                'includes' => ['mate/config.php'],
            ];
            $modified = true;
            $actions[] = ['\u2713', 'Added', 'extra.ai-mate configuration'];
        } else {
            $actions[] = ['\u25cb', 'Exists', 'extra.ai-mate configuration'];
        }

        if (!isset($composerJson['autoload-dev']['psr-4']['Mate\\'])) {
            $composerJson['autoload-dev']['psr-4']['Mate\\'] = 'mate/src/';
            $modified = true;
            $actions[] = ['\u2713', 'Added', 'Mate\\ autoloader'];
        } else {
            $actions[] = ['\u25cb', 'Exists', 'Mate\\ autoloader'];
        }

        if ($modified) {
            file_put_contents(
                $composerJsonPath,
                json_encode($composerJson, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n"
            );
            chmod($composerJsonPath, self::FILE_MODE);
            $actions[] = ['\u2713', 'Updated', 'composer.json'];
        }

        return $actions;
    }
}
