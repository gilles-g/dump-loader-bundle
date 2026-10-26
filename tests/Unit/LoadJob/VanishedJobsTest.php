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

use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\VanishedJobs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class VanishedJobsTest extends TestCase
{
    use JobFixtures;

    private const GRACE_PERIOD = 60;

    #[Test]
    public function it_should_include_a_running_job_whose_lock_is_free(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');

        self::assertTrue($this->vanished(true, $clock)->includes($this->runningJob($clock)->snapshot()));
    }

    #[Test]
    public function it_should_ignore_a_running_job_whose_lock_is_held(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');

        self::assertFalse($this->vanished(false, $clock)->includes($this->runningJob($clock)->snapshot()));
    }

    #[Test]
    public function it_should_ignore_a_pending_job_still_within_its_grace_period(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $snapshot = $this->pendingJob($clock)->snapshot();
        $clock->modify('+59 seconds');

        self::assertFalse($this->vanished(true, $clock)->includes($snapshot));
    }

    #[Test]
    public function it_should_include_a_pending_job_once_its_grace_period_has_elapsed(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $snapshot = $this->pendingJob($clock)->snapshot();
        $clock->modify('+60 seconds');

        self::assertTrue($this->vanished(true, $clock)->includes($snapshot));
    }

    #[Test]
    public function it_should_ignore_a_job_that_is_already_finished(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $job = $this->runningJob($clock);
        $job->fail('boom');

        self::assertFalse($this->vanished(true, $clock)->includes($job->snapshot()));
    }

    #[Test]
    public function it_should_suspect_a_running_job_without_looking_at_its_lock(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');

        $lock = $this->createMock(JobLock::class);
        $lock->expects(self::never())->method('isFree');

        self::assertTrue((new VanishedJobs($lock, $clock, self::GRACE_PERIOD))->suspects($this->runningJob($clock)->snapshot()));
    }

    #[Test]
    public function it_should_see_a_vanished_job_as_lost_without_touching_the_repository(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $snapshot = $this->runningJob($clock)->snapshot();

        $seen = $this->vanished(true, $clock)->seenNow($snapshot);

        self::assertSame(LoadJobStatus::Lost, $seen->status);
        self::assertSame(LoadJobStatus::Running, $snapshot->status);
    }

    #[Test]
    public function it_should_return_an_untouched_snapshot_when_the_job_is_alive(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $snapshot = $this->runningJob($clock)->snapshot();

        self::assertSame($snapshot, $this->vanished(false, $clock)->seenNow($snapshot));
    }

    #[Test]
    public function it_should_refuse_a_negative_grace_period(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VanishedJobs($this->createStub(JobLock::class), new MockClock(), -1);
    }

    private function vanished(bool $lockIsFree, ClockInterface $clock): VanishedJobs
    {
        $lock = $this->createStub(JobLock::class);
        $lock->method('isFree')->willReturn($lockIsFree);

        return new VanishedJobs($lock, $clock, self::GRACE_PERIOD);
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
