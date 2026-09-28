<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Architecture\DriftChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Checks the architecture diagram specs under docs/architecture/ against the
 * real repository, without rendering anything. A dead source path fails the
 * check; a src/* package with no diagram coverage yet only warns.
 *
 * @author Johannes Wachter <wachter.johannes@gmail.com>
 */
#[AsCommand(
    name: 'app:architecture:check',
    description: 'Check the architecture diagram specs for drift against the repository',
)]
final readonly class ArchitectureCheckCommand
{
    public function __construct(
        private DriftChecker $driftChecker,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $io->title('Architecture diagram drift check');

        $result = $this->driftChecker->check();

        foreach ($result['warnings'] as $warning) {
            $io->warning($warning);
        }

        foreach ($result['errors'] as $error) {
            $io->error($error);
        }

        if ([] !== $result['errors']) {
            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%d error(s), %d warning(s).',
            \count($result['errors']),
            \count($result['warnings']),
        ));

        return Command::SUCCESS;
    }
}
