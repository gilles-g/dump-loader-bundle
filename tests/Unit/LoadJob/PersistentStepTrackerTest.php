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

use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\LogLine;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Percentage;
use DumpLoader\LoadJob\PersistentStepTracker;
use DumpLoader\LoadJob\Requester;
use DumpLoader\Step\StepTracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class PersistentStepTrackerTest extends TestCase
{
    use JobFixtures;

    #[Test]
    public function it_should_be_a_step_tracker(): void
    {
        self::assertInstanceOf(StepTracker::class, $this->tracker(new MockClock('2026-09-18 08:00:00')));
    }

    #[Test]
    public function it_should_write_the_line_to_the_job_and_persist_it(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $job = $this->runningJob($clock);

        $jobs = $this->createMock(LoadJobRepository::class);
        $jobs->expects(self::once())->method('save')->with($job);

        $tracker = new PersistentStepTracker($job, $jobs, $this->createStub(AbortSignal::class), $clock);
        $tracker->log(LogTag::Run, 'Restoring 42 MB.');

        $last = $job->snapshot()->log->last();

        self::assertInstanceOf(LogLine::class, $last);
        self::assertSame('Restoring 42 MB.', $last->message);
        self::assertSame(LogTag::Run, $last->tag);
    }

    #[Test]
    public function it_should_report_the_step_percentage_to_the_job(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $job = $this->runningJob($clock);

        $tracker = new PersistentStepTracker(
            $job,
            $this->createStub(LoadJobRepository::class),
            $this->createStub(AbortSignal::class),
            $clock,
        );
        $tracker->progress(Percentage::of(40.0));

        self::assertTrue($job->snapshot()->progress->stepPercentage->equals(Percentage::of(40.0)));
    }

    #[Test]
    public function it_should_save_at_most_twice_per_second(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $job = $this->runningJob($clock);

        $jobs = $this->createMock(LoadJobRepository::class);
        $jobs->expects(self::exactly(2))->method('save');

        $tracker = new PersistentStepTracker($job, $jobs, $this->createStub(AbortSignal::class), $clock);

        $tracker->progress(Percentage::of(10.0));

        $clock->modify('+200 milliseconds');
        $tracker->progress(Percentage::of(20.0));

        $clock->modify('+200 milliseconds');
        $tracker->progress(Percentage::of(30.0));

        $clock->modify('+200 milliseconds');
        $tracker->progress(Percentage::of(40.0));
    }

    #[Test]
    public function it_should_ask_the_abort_signal_whether_an_interruption_was_requested(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');

        $signal = $this->createMock(AbortSignal::class);
        $signal->expects(self::once())->method('isRaised')->with($this->aJobId())->willReturn(true);

        $tracker = new PersistentStepTracker(
            $this->runningJob($clock),
            $this->createStub(LoadJobRepository::class),
            $signal,
            $clock,
        );

        self::assertTrue($tracker->abortRequested());
    }

    private function tracker(ClockInterface $clock): PersistentStepTracker
    {
        return new PersistentStepTracker(
            $this->runningJob($clock),
            $this->createStub(LoadJobRepository::class),
            $this->createStub(AbortSignal::class),
            $clock,
        );
    }

    private function runningJob(ClockInterface $clock): LoadJob
    {
        $job = LoadJob::request(
            $this->aJobId(),
            $this->aDump(),
            LoadOptions::none(),
            $this->plannedSteps(),
            Requester::unknown(),
            $clock,
        );
        $job->start();
        $job->startStep('restore_database');

        return $job;
    }
}
