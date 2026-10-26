<?php

declare(strict_types=1);

/*
 * This file is part of the gilles-g/dump-loader package.
 *
 * (c) gilles-g <ggauthier@spiriit.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DumpLoader\Command;

use DumpLoader\Command\Screen\LoadDumpScreen;
use DumpLoader\Command\Screen\Screens;
use DumpLoader\Dump\DumpNotFound;
use DumpLoader\Dump\DumpNotRestorable;
use DumpLoader\LoadJob\Dashboard;
use DumpLoader\LoadJob\DashboardQuery;
use DumpLoader\LoadJob\FollowedLoadJob;
use DumpLoader\LoadJob\FollowLoadJobs;
use DumpLoader\LoadJob\LoadJobAlreadyRunning;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\RequestLoadJob;
use DumpLoader\LoadJob\RequestLoadJobHandler;
use DumpLoader\LoadJob\UnreadableLoadJob;
use DumpLoader\Requirements\CheckRequirements;
use DumpLoader\Requirements\Requirement;
use DumpLoader\Step\StepDefinition;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function Symfony\Component\String\u;

#[AsCommand(name: 'dump-loader:load', description: 'Chooses a dump, loads it into the application database and follows the load job.')]
final class LoadDumpCommand extends Command implements SignalableCommandInterface
{
    private const SIGINT = 2;
    private const INTERRUPTED = 128 + self::SIGINT;

    private ?FollowedLoadJob $followed = null;

    public function __construct(
        private readonly DashboardQuery $dashboardQuery,
        private readonly CheckRequirements $checkRequirements,
        private readonly RequestLoadJobHandler $requestLoadJobHandler,
        private readonly FollowLoadJobs $followLoadJobs,
        private readonly Screens $screens,
        private readonly Labels $labels,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('dump', InputArgument::OPTIONAL, 'Path of the dump to load, as listed by the dashboard')
            ->addOption('option', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Optional step to run (repeatable)')
            ->addOption('no-options', null, InputOption::VALUE_NONE, 'Run no optional step')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Do not ask before dropping every table of the target database')
            ->addOption('detach', null, InputOption::VALUE_NONE, 'Start the load job and return without following it')
            ->addOption('requester', null, InputOption::VALUE_REQUIRED, 'Name recorded as the requester of the load job')
            ->addOption('no-tui', null, InputOption::VALUE_NONE, 'Use the plain console output even when the rich terminal UI is available')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dashboard = $this->dashboardQuery->current();

        $this->warnAboutRequirements($io);

        if (null !== $dashboard->unreadableJobDetail) {
            $io->error($dashboard->unreadableJobDetail);

            return Command::FAILURE;
        }

        if (null !== $dashboard->activeJob) {
            $io->error(\sprintf('Load job "%s" is still active, only one load job runs at a time.', $dashboard->activeJob->id->value));

            return Command::FAILURE;
        }

        if ($dashboard->dumps->isEmpty()) {
            $io->error('There is no dump to load.');

            return Command::FAILURE;
        }

        $screen = $this->screens->open($input, $output, !$input->getOption('no-tui'));

        try {
            $request = $this->request($input, $screen, $dashboard);
        } catch (LoadRefused $refusal) {
            $io->error($refusal->getMessage());

            return Command::FAILURE;
        }

        try {
            $id = ($this->requestLoadJobHandler)($request);
        } catch (LoadJobAlreadyRunning|UnreadableLoadJob|DumpNotFound $refusal) {
            $io->error($refusal->getMessage());

            return Command::FAILURE;
        } catch (DumpNotRestorable $refusal) {
            $io->error(\sprintf('The dump cannot be restored: %s', $refusal->getMessage()));

            return Command::FAILURE;
        }

        $this->followed = $this->followLoadJobs->follow($id);

        if ($input->getOption('detach') && !$this->followed->isOver()) {
            $io->success(\sprintf('Load job "%s" started, follow it in the monitor.', $id->value));

            return Command::SUCCESS;
        }

        if (!$this->followed->isOver()) {
            $screen->follow($this->followed);
        }

        $report = new LoadJobReport($this->followed->snapshot(), $this->labels);
        $report->writeTo($io);

        return $report->isSuccessful() ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return list<int>
     */
    public function getSubscribedSignals(): array
    {
        return [self::SIGINT];
    }

    public function handleSignal(int $signal, int|false $previousExitCode = 0): int|false
    {
        if (null === $this->followed || $this->followed->isOver()) {
            return self::INTERRUPTED;
        }

        $this->followed->requestAbort();

        return false;
    }

    private function request(InputInterface $input, LoadDumpScreen $screen, Dashboard $dashboard): RequestLoadJob
    {
        $dumpPath = $this->dumpPath($input, $screen, $dashboard);
        $options = $this->options($input, $screen, $dashboard);
        $database = $dashboard->targetDatabase ?? 'the target database';

        if (!$input->getOption('force')) {
            if (!$input->isInteractive()) {
                throw LoadRefused::withoutForce($database);
            }

            if (!$screen->confirm($database)) {
                throw LoadRefused::cancelled();
            }
        }

        return new RequestLoadJob(
            dumpPath: $dumpPath,
            options: $options,
            requester: $this->requester($input),
        );
    }

    private function dumpPath(InputInterface $input, LoadDumpScreen $screen, Dashboard $dashboard): string
    {
        $dumpPath = $input->getArgument('dump');

        if (\is_string($dumpPath)) {
            return $dumpPath;
        }

        if (!$input->isInteractive()) {
            throw LoadRefused::withoutDump();
        }

        return $screen->chooseDump($dashboard->dumps);
    }

    private function options(InputInterface $input, LoadDumpScreen $screen, Dashboard $dashboard): LoadOptions
    {
        if ($input->getOption('no-options')) {
            return LoadOptions::none();
        }

        /** @var list<string> $names */
        $names = $input->getOption('option');

        if ([] !== $names) {
            $this->ensureAvailable($names, $dashboard->availableOptions);

            return LoadOptions::fromNames($names);
        }

        if (!$input->isInteractive()) {
            return $dashboard->defaultOptions;
        }

        return $screen->chooseOptions($dashboard->availableOptions, $dashboard->defaultOptions);
    }

    /**
     * @param list<string>         $names
     * @param list<StepDefinition> $availableOptions
     */
    private function ensureAvailable(array $names, array $availableOptions): void
    {
        $available = array_map(static fn (StepDefinition $option): string => $option->name, $availableOptions);
        $unknown = array_diff($names, $available);

        if ([] !== $unknown) {
            throw LoadRefused::unknownOptions(array_values($unknown), $available);
        }
    }

    private function requester(InputInterface $input): Requester
    {
        $name = $input->getOption('requester') ?? getenv('USER');

        if (!\is_string($name) || u($name)->trim()->isEmpty()) {
            return Requester::unknown();
        }

        return Requester::named($name);
    }

    private function warnAboutRequirements(SymfonyStyle $io): void
    {
        $unsatisfied = ($this->checkRequirements)()->unsatisfied();

        if ([] === $unsatisfied) {
            return;
        }

        $io->warning([
            'Some requirements are not met, the load job may fail:',
            ...array_map(
                fn (Requirement $requirement): string => $this->labels->translate($requirement->label).': '.$requirement->detail,
                $unsatisfied,
            ),
        ]);
    }
}
