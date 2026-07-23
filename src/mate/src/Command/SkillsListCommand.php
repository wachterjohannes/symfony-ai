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
use Symfony\AI\Mate\Command\Trait\EnsuresToonFormatAvailabilityTrait;
use Symfony\AI\Mate\Discovery\ComposerExtensionDiscovery;
use Symfony\AI\Mate\Service\ExtensionConfigSynchronizer;
use Symfony\AI\Mate\Skill\SkillContentHasher;
use Symfony\AI\Mate\Skill\SkillDiscovery;
use Symfony\AI\Mate\Skill\SkillLock;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Read-only diagnostic listing of declared and installed Mate skills.
 *
 * Cross-references the skills declared by extensions (source), the user intent in
 * mate/extensions.php (enabled/overridden) and the facts recorded in mate/skills.lock.php
 * (strategy, content hash). The status column flags skills that are disabled, not yet installed,
 * broken (generated folder missing) or stale (generated content drifted from the lock hash).
 *
 * @phpstan-import-type SkillLockEntry from SkillLock
 *
 * @phpstan-type SkillRow array{
 *     installed_name: string,
 *     original_name: string,
 *     package: string,
 *     enabled: bool,
 *     overridden: bool,
 *     strategy: string,
 *     source: string,
 *     status: string,
 * }
 *
 * @author Johannes Wachter <johannes@sulu.io>
 */
#[AsCommand('skills:list', 'List declared and installed Mate skills with their status')]
class SkillsListCommand extends Command
{
    use EnsuresToonFormatAvailabilityTrait;

    public function __construct(
        private string $rootDir,
        private ComposerExtensionDiscovery $extensionDiscovery,
        private ExtensionConfigSynchronizer $extensionConfigSynchronizer,
        private SkillDiscovery $skillDiscovery,
        private SkillLock $lock,
        private SkillContentHasher $hasher,
    ) {
        parent::__construct(self::getDefaultName());
    }

    public static function getDefaultName(): string
    {
        return 'skills:list';
    }

    public static function getDefaultDescription(): string
    {
        return 'List declared and installed Mate skills with their status';
    }

    protected function configure(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (table, json, toon)', 'table');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $format = $input->getOption('format');
        \assert(\is_string($format));

        if (!$this->ensureToonFormatAvailable($io, $format)) {
            return Command::FAILURE;
        }

        $rows = $this->collectRows();

        if ('json' === $format) {
            $output->writeln(json_encode($this->getArrayResult($rows), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        if ('toon' === $format) {
            $output->writeln(Toon::encode($this->getArrayResult($rows)));

            return Command::SUCCESS;
        }

        $this->outputTable($rows, $io);

        return Command::SUCCESS;
    }

    /**
     * @return list<SkillRow>
     */
    private function collectRows(): array
    {
        $extensions = $this->extensionDiscovery->discover();
        $extensions['_custom'] = $this->extensionDiscovery->discoverRootProject();

        $skills = $this->skillDiscovery->discover($extensions);
        $intent = $this->extensionConfigSynchronizer->read();
        $lock = $this->lock->read();

        $rows = [];
        $seen = [];

        foreach ($skills as $skill) {
            $ownerIntent = $intent[$skill->package] ?? [];
            $ownerEnabled = $ownerIntent['enabled'] ?? true;
            $skillIntent = $ownerIntent['skills'][$skill->originalName] ?? [];
            $enabled = $ownerEnabled && ($skillIntent['enabled'] ?? true);
            $override = $skillIntent['override'] ?? false;

            $lockEntry = $lock[$skill->installedName] ?? null;
            $seen[$skill->installedName] = true;

            $rows[] = [
                'installed_name' => $skill->installedName,
                'original_name' => $skill->originalName,
                'package' => $skill->package,
                'enabled' => $enabled,
                'overridden' => null !== $lockEntry ? $lockEntry['overridden'] : $override,
                'strategy' => null !== $lockEntry ? $lockEntry['strategy'] : '-',
                'source' => $skill->source,
                'status' => $this->resolveStatus($skill->installedName, $enabled, $lockEntry),
            ];
        }

        // Lock entries without a matching declared skill: the source package or skill vanished.
        foreach ($lock as $installedName => $lockEntry) {
            if (isset($seen[$installedName])) {
                continue;
            }

            $rows[] = [
                'installed_name' => $installedName,
                'original_name' => $lockEntry['original_name'],
                'package' => $lockEntry['package'],
                'enabled' => false,
                'overridden' => $lockEntry['overridden'],
                'strategy' => $lockEntry['strategy'],
                'source' => $lockEntry['source'],
                'status' => 'orphaned',
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['installed_name'] <=> $b['installed_name']);

        return $rows;
    }

    /**
     * @param SkillLockEntry|null $lockEntry
     */
    private function resolveStatus(string $installedName, bool $enabled, ?array $lockEntry): string
    {
        if (!$enabled) {
            return 'disabled';
        }

        if (null === $lockEntry) {
            return 'not installed';
        }

        $target = $this->rootDir.'/.agents/skills/'.$installedName;
        if (!is_dir($target)) {
            return 'broken';
        }

        if ($this->hasher->hash($target) !== $lockEntry['content_hash']) {
            return 'stale';
        }

        return 'ok';
    }

    /**
     * @param list<SkillRow> $rows
     */
    private function outputTable(array $rows, SymfonyStyle $io): void
    {
        $io->title('Mate Skills');

        if ([] === $rows) {
            $io->warning('No skills declared by any installed extension or the root project.');

            return;
        }

        $table = new Table($io);
        $table->setHeaders(['Installed Name', 'Original', 'Package', 'Enabled', 'Overridden', 'Strategy', 'Status']);

        foreach ($rows as $row) {
            $table->addRow([
                $row['installed_name'],
                $row['original_name'],
                $row['package'],
                $row['enabled'] ? 'yes' : 'no',
                $row['overridden'] ? 'yes' : 'no',
                $row['strategy'],
                $row['status'],
            ]);
        }

        $table->render();

        $io->newLine();
        $io->text(\sprintf('Total: <info>%d</info> skill(s)', \count($rows)));
    }

    /**
     * @param list<SkillRow> $rows
     *
     * @return array{skills: list<SkillRow>, summary: array{total: int}}
     */
    private function getArrayResult(array $rows): array
    {
        return [
            'skills' => $rows,
            'summary' => [
                'total' => \count($rows),
            ],
        ];
    }
}
