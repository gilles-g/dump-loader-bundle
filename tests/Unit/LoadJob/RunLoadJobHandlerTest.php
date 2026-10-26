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

namespace DumpLoader\Tests\Unit\LoadJob;

use DumpLoader\Dump\DumpSource;
use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\InvalidTransition;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobAlreadyRunning;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\PlannedStep;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\RunLoadJob;
use DumpLoader\LoadJob\RunLoadJobHandler;
use DumpLoader\LoadJob\StepStatus;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepFailed;
use DumpLoader\Step\StepSequence;
use DumpLoader\Tests\Double\FakeStep;
use DumpLoader\Tests\Double\InMemoryLoadJobRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class RunLoadJobHandlerTest extends TestCase
{
    use JobFixtures;

    #[Test]
    public function it_should_run_every_planned_step_and_complete_the_job(): void
    {
        $jobs = $this->repositoryWithPendingJob();
        $restore = FakeStep::named('restore_database', StepDefinition::RANK_RESTORE_DATABASE);
        $caches = FakeStep::named('clear_caches', StepDefinition::RANK_CLEAR_CACHES);

        ($this->handler($jobs, [$restore, $caches]))(new RunLoadJob($this->aJobId()));

        $snapshot = $jobs->get($this->aJobId())->snapshot();

        self::assertSame(LoadJobStatus::Completed, $snapshot->status);
        self::assertSame([$this->aJobId()->value], $restore->ranFor);
        self::assertSame([$this->aJobId()->value], $caches->ranFor);
        self::assertSame(
            [StepStatus::Done, StepStatus::Done],
            array_map(static fn (PlannedStep $step): StepStatus => $step->status, $snapshot->steps),
        );
    }

    #[Test]
    public function it_should_fail_the_job_when_a_step_blows_up(): void
    {
        $jobs = $this->repositoryWithPendingJob();
        $steps = [
            FakeStep::named('restore_database', StepDefinition::RANK_RESTORE_DATABASE)
                ->failingWith(StepFailed::because('mysql exited with code 1')),
            FakeStep::named('clear_caches', StepDefinition::RANK_CLEAR_CACHES),
        ];

        ($this->handler($jobs, $steps))(new RunLoadJob($this->aJobId()));

        $snapshot = $jobs->get($this->aJobId())->snapshot();

        self::assertSame(LoadJobStatus::Failed, $snapshot->status);
        self::assertSame('mysql exited with code 1', $snapshot->failureReason);
        self::assertSame(StepStatus::Failed, $snapshot->steps[0]->status);
        self::assertSame(StepStatus::Todo, $snapshot->steps[1]->status);
        self::assertSame([], $steps[1]->ranFor);
    }

    #[Test]
    public function it_should_abort_the_job_when_the_signal_is_raised_before_a_step(): void
    {
        $jobs = $this->repositoryWithPendingJob();
        $steps = [FakeStep::named('restore_database', StepDefinition::RANK_RESTORE_DATABASE)];

        ($this->handler($jobs, $steps, abortRaised: true))(new RunLoadJob($this->aJobId()));

        $snapshot = $jobs->get($this->aJobId())->snapshot();

        self::assertSame(LoadJobStatus::Aborted, $snapshot->status);
        self::assertSame([], $steps[0]->ranFor);
    }

    #[Test]
    public function it_should_clear_the_abort_signal_once_the_job_is_over(): void
    {
        $jobs = $this->repositoryWithPendingJob();

        $signal = $this->createMock(AbortSignal::class);
        $signal->method('isRaised')->willReturn(false);
        $signal->expects(self::once())->method('clear')->with($this->aJobId());

        ($this->handler($jobs, $this->registeredSteps(), signal: $signal))(new RunLoadJob($this->aJobId()));

        self::assertSame(LoadJobStatus::Completed, $jobs->get($this->aJobId())->snapshot()->status);
    }

    #[Test]
    public function it_should_refuse_to_run_when_the_lock_cannot_be_acquired(): void
    {
        $jobs = $this->repositoryWithPendingJob();

        $this->expectException(LoadJobAlreadyRunning::class);

        ($this->handler($jobs, $this->registeredSteps(), lockAcquired: false))(new RunLoadJob($this->aJobId()));
    }

    #[Test]
    public function it_should_refuse_to_run_a_job_already_declared_lost(): void
    {
        $jobs = $this->repositoryWithPendingJob();
        $lost = $jobs->get($this->aJobId());
        $lost->markLost();
        $jobs->save($lost);
        $steps = $this->registeredSteps();

        try {
            ($this->handler($jobs, $steps))(new RunLoadJob($this->aJobId()));
            self::fail('A lost job should not run.');
        } catch (InvalidTransition) {
        }

        self::assertSame(LoadJobStatus::Lost, $jobs->get($this->aJobId())->snapshot()->status);
        self::assertSame([], $steps[0]->ranFor);
    }

    #[Test]
    public function it_should_fail_the_job_when_a_planned_step_is_no_longer_registered(): void
    {
        $jobs = $this->repositoryWithPendingJob();

        ($this->handler($jobs, []))(new RunLoadJob($this->aJobId()));

        $snapshot = $jobs->get($this->aJobId())->snapshot();

        self::assertSame(LoadJobStatus::Failed, $snapshot->status);
        self::assertStringContainsString('restore_database', (string) $snapshot->failureReason);
    }

    /**
     * @return list<FakeStep>
     */
    private function registeredSteps(): array
    {
        return [
            FakeStep::named('restore_database', StepDefinition::RANK_RESTORE_DATABASE),
            FakeStep::named('clear_caches', StepDefinition::RANK_CLEAR_CACHES),
        ];
    }

    private function repositoryWithPendingJob(): InMemoryLoadJobRepository
    {
        $jobs = new InMemoryLoadJobRepository();
        $jobs->save(LoadJob::request(
            $this->aJobId(),
            $this->aDump(),
            LoadOptions::none(),
            $this->plannedSteps(),
            Requester::unknown(),
            new MockClock('2026-09-18 08:00:00'),
        ));

        return $jobs;
    }

    /**
     * @param list<FakeStep> $steps
     */
    private function handler(
        InMemoryLoadJobRepository $jobs,
        array $steps,
        bool $abortRaised = false,
        bool $lockAcquired = true,
        ?AbortSignal $signal = null,
    ): RunLoadJobHandler {
        $clock = new MockClock('2026-09-18 08:00:00');

        $dumps = $this->createStub(DumpSource::class);
        $dumps->method('find')->willReturn($this->aDump());

        $lock = $this->createStub(JobLock::class);
        $lock->method('acquire')->willReturn($lockAcquired);

        return new RunLoadJobHandler(
            jobs: $jobs,
            steps: new StepSequence($steps),
            dumps: $dumps,
            lock: $lock,
            abortSignal: $signal ?? $this->abortSignal($abortRaised),
            clock: $clock,
        );
    }

    private function abortSignal(bool $raised): AbortSignal
    {
        $signal = $this->createStub(AbortSignal::class);
        $signal->method('isRaised')->willReturn($raised);

        return $signal;
    }
}
