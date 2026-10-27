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

use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\RunLoadJob;
use DumpLoader\LoadJob\RunLoadJobHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'dump-loader:run', description: 'Runs a load job that was already requested.')]
final class RunLoadJobCommand extends Command
{
    public function __construct(
        private readonly RunLoadJobHandler $runLoadJobHandler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Identifier of the load job to run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = $input->getArgument('id');
        \assert(\is_string($id));

        try {
            ($this->runLoadJobHandler)(new RunLoadJob(LoadJobId::fromString($id)));
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf('Load job "%s" is over.', $id));

        return Command::SUCCESS;
    }
}
