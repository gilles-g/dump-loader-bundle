<?php

declare(strict_types=1);

/*
 * This file is part of the gilles-g/dump-loader-bundle package.
 *
 * (c) gilles-g <ggauthier@spiriit.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DumpLoader\Command;

use DumpLoader\Requirements\CheckRequirements;
use DumpLoader\Requirements\Requirement;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'dump-loader:check', description: 'Checks that the host can actually run a load job.')]
final class CheckRequirementsCommand extends Command
{
    public function __construct(
        private readonly CheckRequirements $checkRequirements,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $requirements = ($this->checkRequirements)();

        $rows = [];

        foreach ($requirements as $requirement) {
            $rows[] = [
                $requirement->satisfied ? '<info>OK</info>' : '<error>KO</error>',
                $requirement->label,
                $requirement->detail,
            ];
        }

        $io->table(['', 'Requirement', 'Detail'], $rows);

        if ($requirements->allSatisfied()) {
            $io->success('Every requirement is met.');

            return Command::SUCCESS;
        }

        $io->error(array_map(
            static fn (Requirement $requirement): string => $requirement->label.': '.$requirement->detail,
            $requirements->unsatisfied(),
        ));

        return Command::FAILURE;
    }
}
