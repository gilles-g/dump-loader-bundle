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

namespace DumpLoader\Tests\Unit\LoadJob;

use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadJobs;
use DumpLoader\LoadJob\LoadJobSnapshot;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\ReconcileLostJobs;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\VanishedJobs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class ReconcileLostJobsTest extends TestCase
{
    use JobFixtures;

    private const int GRACE_PERIOD = 60;

    #[Test]
    public function it_should_mark_a_running_job_lost_once_it_holds_its_lock(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $snapshot = $this->runningJob($clock)->snapshot();

        $saved = null;
        $jobs = $this->repositoryListing($snapshot, LoadJob::fromSnapshot($snapshot, $clock));
        $jobs->expects(self::once())->method('save')->willReturnCallback(
            static function (LoadJob $job) use (&$saved): void {
                $saved = $job;
            },
        );

        $this->reconcile($jobs, $this->freeLock(), $clock)();

        self::assertInstanceOf(LoadJob::class, $saved);
        self::assertSame(LoadJobStatus::Lost, $saved->snapshot()->status);
    }

    #[Test]
    public function it_should_leave_a_running_job_alone_while_its_lock_is_held(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $snapshot = $this->runningJob($clock)->snapshot();

        $jobs = $this->createMock(LoadJobRepository::class);
        $jobs->method('active')->willReturn(new LoadJobs($snapshot));
        $jobs->expects(self::never())->method('save');

        $lock = $this->createMock(JobLock::class);
        $lock->method('isFree')->willReturn(false);
        $lock->expects(self::never())->method('acquire');

        $this->reconcile($jobs, $lock, $clock)();
    }

    #[Test]
    public function it_should_leave_a_job_alone_when_its_runner_takes_the_lock_first(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $snapshot = $this->pendingJob($clock)->snapshot();
        $clock->modify('+60 seconds');

        $jobs = $this->createMock(LoadJobRepository::class);
        $jobs->method('active')->willReturn(new LoadJobs($snapshot));
        $jobs->expects(self::never())->method('get');
        $jobs->expects(self::never())->method('save');

        $lock = $this->createMock(JobLock::class);
        $lock->method('isFree')->willReturn(true);
        $lock->method('acquire')->willReturn(false);
        $lock->expects(self::never())->method('release');

        $this->reconcile($jobs, $lock, $clock)();
    }

    #[Test]
    public function it_should_leave_a_job_that_finished_since_it_was_listed(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $job = $this->runningJob($clock);
        $listed = $job->snapshot();
        $job->completeStep();
        $job->complete();

        $jobs = $this->repositoryListing($listed, $job);
        $jobs->expects(self::never())->method('save');

        $this->reconcile($jobs, $this->freeLock(), $clock)();
    }

    #[Test]
    public function it_should_leave_a_pending_job_its_grace_period_to_take_the_lock(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $snapshot = $this->pendingJob($clock)->snapshot();
        $clock->modify('+59 seconds');

        $jobs = $this->createMock(LoadJobRepository::class);
        $jobs->method('active')->willReturn(new LoadJobs($snapshot));
        $jobs->expects(self::never())->method('save');

        $lock = $this->createMock(JobLock::class);
        $lock->method('isFree')->willReturn(true);
        $lock->expects(self::never())->method('acquire');

        $this->reconcile($jobs, $lock, $clock)();
    }

    #[Test]
    public function it_should_mark_a_pending_job_lost_once_its_grace_period_has_elapsed(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $snapshot = $this->pendingJob($clock)->snapshot();
        $clock->modify('+60 seconds');

        $saved = null;
        $jobs = $this->repositoryListing($snapshot, LoadJob::fromSnapshot($snapshot, $clock));
        $jobs->expects(self::once())->method('save')->willReturnCallback(
            static function (LoadJob $job) use (&$saved): void {
                $saved = $job;
            },
        );

        $this->reconcile($jobs, $this->freeLock(), $clock)();

        self::assertInstanceOf(LoadJob::class, $saved);
        self::assertSame(LoadJobStatus::Lost, $saved->snapshot()->status);
    }

    #[Test]
    public function it_should_release_the_lock_even_when_the_save_fails(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $snapshot = $this->runningJob($clock)->snapshot();

        $jobs = $this->repositoryListing($snapshot, LoadJob::fromSnapshot($snapshot, $clock));
        $jobs->expects(self::once())->method('save')->willThrowException(new \RuntimeException('disk full'));

        $this->expectExceptionMessage('disk full');

        $this->reconcile($jobs, $this->freeLock(), $clock)();
    }

    #[Test]
    public function it_should_save_nothing_when_no_job_is_active(): void
    {
        $jobs = $this->createMock(LoadJobRepository::class);
        $jobs->method('active')->willReturn(new LoadJobs());
        $jobs->expects(self::never())->method('save');

        $this->reconcile($jobs, $this->createStub(JobLock::class), new MockClock('2026-09-18 08:00:00'))();
    }

    private function reconcile(LoadJobRepository $jobs, JobLock $lock, ClockInterface $clock): ReconcileLostJobs
    {
        return new ReconcileLostJobs($jobs, new VanishedJobs($lock, $clock, self::GRACE_PERIOD), $lock);
    }

    private function repositoryListing(LoadJobSnapshot $listed, LoadJob $current): LoadJobRepository&MockObject
    {
        $jobs = $this->createMock(LoadJobRepository::class);
        $jobs->method('active')->willReturn(new LoadJobs($listed));
        $jobs->expects(self::once())->method('get')->with($listed->id)->willReturn($current);

        return $jobs;
    }

    private function freeLock(): JobLock
    {
        $lock = $this->createMock(JobLock::class);
        $lock->method('isFree')->willReturn(true);
        $lock->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release');

        return $lock;
    }

    private function pendingJob(ClockInterface $clock): LoadJob
    {
        return LoadJob::request(
            $this->aJobId(),
            $this->aDump(),
            LoadOptions::none(),
            $this->plannedSteps(),
            Requester::unknown(),
            $clock,
        );
    }

    private function runningJob(ClockInterface $clock): LoadJob
    {
        $job = $this->pendingJob($clock);
        $job->start();
        $job->startStep('restore_database');

        return $job;
    }
}
