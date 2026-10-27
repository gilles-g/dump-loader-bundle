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

use DumpLoader\Dump\DumpSource;
use DumpLoader\Step\LoadContext;
use DumpLoader\Step\StepSequence;
use Psr\Clock\ClockInterface;

final readonly class RunLoadJobHandler
{
    private const LOCK_ATTEMPTS = 3;
    private const MICROSECONDS_BETWEEN_ATTEMPTS = 100_000;

    public function __construct(
        private LoadJobRepository $jobs,
        private StepSequence $steps,
        private DumpSource $dumps,
        private JobLock $lock,
        private AbortSignal $abortSignal,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(RunLoadJob $command): void
    {
        if (!$this->acquire($command->id)) {
            throw LoadJobAlreadyRunning::withId($command->id);
        }

        $job = $this->jobs->get($command->id);
        $tracker = new PersistentStepTracker($job, $this->jobs, $this->abortSignal, $this->clock);

        $job->start();
        $this->jobs->save($job);

        try {
            $this->runSteps($job, $tracker);
            $job->complete();
        } catch (LoadJobAborted) {
            $job->abort();
        } catch (\Throwable $exception) {
            $job->fail($exception->getMessage());
        } finally {
            $this->jobs->save($job);
            $this->abortSignal->clear($command->id);
        }
    }

    private function runSteps(LoadJob $job, PersistentStepTracker $tracker): void
    {
        $snapshot = $job->snapshot();
        $context = new LoadContext(
            id: $snapshot->id,
            dump: $this->dumps->find($snapshot->dumpPath),
            options: $snapshot->options,
        );

        foreach ($snapshot->steps as $planned) {
            if ($tracker->abortRequested()) {
                throw LoadJobAborted::onRequest();
            }

            $job->startStep($planned->name);
            $this->jobs->save($job);

            $this->steps->named($planned->name)->run($context, $tracker);

            $job->completeStep();
            $this->jobs->save($job);
        }
    }

    private function acquire(LoadJobId $id): bool
    {
        for ($attempt = 1; $attempt <= self::LOCK_ATTEMPTS; ++$attempt) {
            if ($this->lock->acquire($id)) {
                return true;
            }

            if ($attempt < self::LOCK_ATTEMPTS) {
                usleep(self::MICROSECONDS_BETWEEN_ATTEMPTS);
            }
        }

        return false;
    }
}
