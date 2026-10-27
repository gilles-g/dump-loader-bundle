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

namespace DumpLoader\LoadJob;

use DumpLoader\Database\RestorePolicy;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Execution\LoadJobLauncher;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepSequence;
use Psr\Clock\ClockInterface;
use Symfony\Component\Lock\LockFactory;

final readonly class RequestLoadJobHandler
{
    private const CRITICAL_SECTION = 'dump_loader.request';

    public function __construct(
        private DumpSource $dumps,
        private RestorePolicy $restorePolicy,
        private StepSequence $steps,
        private LoadJobRepository $jobs,
        private ReconcileLostJobs $reconcileLostJobs,
        private LoadJobLauncher $launcher,
        private LockFactory $locks,
        private ClockInterface $clock,
        private int $retention,
    ) {
    }

    public function __invoke(RequestLoadJob $command): LoadJobId
    {
        $lock = $this->locks->createLock(self::CRITICAL_SECTION);
        $lock->acquire(true);

        try {
            ($this->reconcileLostJobs)();
            $this->jobs->prune($this->retention);

            $active = $this->jobs->active()->first();

            if (null !== $active) {
                throw LoadJobAlreadyRunning::withId($active->id);
            }

            $dump = $this->dumps->find($command->dumpPath);
            $this->restorePolicy->ensureRestorable($this->dumps->open($dump));

            $job = LoadJob::request(
                id: LoadJobId::generate(),
                dump: $dump,
                options: $command->options,
                steps: $this->plannedSteps($command->options),
                requester: $command->requester,
                clock: $this->clock,
            );

            $this->jobs->save($job);
            $this->launch($job);

            return $job->id();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<PlannedStep>
     */
    private function plannedSteps(LoadOptions $options): array
    {
        return array_map(
            static fn (Step $step): PlannedStep => PlannedStep::fromDefinition($step->definition()),
            $this->steps->plan($options),
        );
    }

    private function launch(LoadJob $job): void
    {
        try {
            $this->launcher->launch($job->id());
        } catch (\Throwable $exception) {
            $job->fail($exception->getMessage());
            $this->jobs->save($job);

            throw $exception;
        }
    }
}
