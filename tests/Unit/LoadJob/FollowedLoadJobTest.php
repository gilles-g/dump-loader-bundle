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

use DumpLoader\LoadJob\AbortLoadJobHandler;
use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\ExecutionLog;
use DumpLoader\LoadJob\FollowedLoadJob;
use DumpLoader\LoadJob\FollowLoadJobs;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobSnapshot;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\LogLine;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Progress;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\VanishedJobs;
use DumpLoader\Tests\Double\InMemoryLoadJobRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class FollowedLoadJobTest extends TestCase
{
    use JobFixtures;

    private MockClock $clock;

    private InMemoryLoadJobRepository $jobs;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-18 08:00:00');
        $this->jobs = new InMemoryLoadJobRepository();
    }

    #[Test]
    public function it_should_give_only_the_log_lines_not_seen_yet(): void
    {
        $job = $this->aRequestedJob();
        $followed = $this->follow($job);

        self::assertSame(['Load job queued for dump "prod-2026-09-18.sql.gz".'], $this->messages($followed->newLogLines()));
        self::assertSame([], $followed->newLogLines());

        $job->start();
        $followed->refresh();

        self::assertSame(['Load job started.'], $this->messages($followed->newLogLines()));
        self::assertSame(LoadJobStatus::Running, $followed->snapshot()->status);
    }

    #[Test]
    public function it_should_skip_the_lines_the_log_dropped_while_rotating(): void
    {
        $followed = $this->followSnapshot($this->aSnapshotLogging(['a', 'b', 'c'], omittedLines: 0));
        $followed->newLogLines();

        $this->jobs->save(LoadJob::fromSnapshot($this->aSnapshotLogging(['c', 'd', 'e'], omittedLines: 2), $this->clock));
        $followed->refresh();

        self::assertSame(['d', 'e'], $this->messages($followed->newLogLines()));
    }

    #[Test]
    public function it_should_give_every_line_when_the_log_rotated_past_the_seen_ones(): void
    {
        $followed = $this->followSnapshot($this->aSnapshotLogging(['a', 'b'], omittedLines: 0));
        $followed->newLogLines();

        $this->jobs->save(LoadJob::fromSnapshot($this->aSnapshotLogging(['f', 'g'], omittedLines: 5), $this->clock));
        $followed->refresh();

        self::assertSame(['f', 'g'], $this->messages($followed->newLogLines()));
    }

    #[Test]
    public function it_should_request_the_abort_only_once(): void
    {
        $job = $this->aRequestedJob();

        $abortSignal = $this->createMock(AbortSignal::class);
        $abortSignal->expects(self::once())->method('request')->with($job->id());

        $followed = $this->follow($job, abortSignal: $abortSignal);
        $followed->requestAbort();
        $followed->requestAbort();

        self::assertTrue($followed->isAborting());
    }

    #[Test]
    public function it_should_not_abort_a_job_that_is_over(): void
    {
        $job = $this->aRequestedJob();
        $job->start();
        $job->complete();

        $abortSignal = $this->createMock(AbortSignal::class);
        $abortSignal->expects(self::never())->method('request');

        $followed = $this->follow($job, abortSignal: $abortSignal);
        $followed->requestAbort();

        self::assertTrue($followed->isOver());
        self::assertFalse($followed->isAborting());
    }

    #[Test]
    public function it_should_ignore_a_job_finishing_right_before_its_abort(): void
    {
        $job = $this->aRequestedJob();
        $followed = $this->follow($job);

        $job->start();
        $job->complete();
        $followed->requestAbort();

        self::assertTrue($followed->isAborting());
        self::assertFalse($followed->isOver());
    }

    #[Test]
    public function it_should_see_a_job_whose_process_vanished_as_lost(): void
    {
        $job = $this->aRequestedJob();
        $job->start();

        $followed = $this->follow($job, processAlive: false);

        self::assertSame(LoadJobStatus::Lost, $followed->snapshot()->status);
        self::assertTrue($followed->isOver());
    }

    private function aRequestedJob(): LoadJob
    {
        $job = LoadJob::request(
            id: $this->aJobId(),
            dump: $this->aDump(),
            options: LoadOptions::none(),
            steps: $this->plannedSteps(),
            requester: Requester::unknown(),
            clock: $this->clock,
        );

        $this->jobs->save($job);

        return $job;
    }

    private function follow(LoadJob $job, ?AbortSignal $abortSignal = null, bool $processAlive = true): FollowedLoadJob
    {
        return $this->followLoadJobs($abortSignal, $processAlive)->follow($job->id());
    }

    private function followSnapshot(LoadJobSnapshot $snapshot): FollowedLoadJob
    {
        $this->jobs->save(LoadJob::fromSnapshot($snapshot, $this->clock));

        return $this->follow($this->jobs->get($snapshot->id));
    }

    private function followLoadJobs(?AbortSignal $abortSignal, bool $processAlive): FollowLoadJobs
    {
        $lock = $this->createStub(JobLock::class);
        $lock->method('isFree')->willReturn(!$processAlive);

        $vanished = new VanishedJobs($lock, $this->clock, 60);

        return new FollowLoadJobs(
            $this->jobs,
            $vanished,
            new AbortLoadJobHandler($this->jobs, $abortSignal ?? $this->createStub(AbortSignal::class)),
        );
    }

    /**
     * @param list<string> $messages
     */
    private function aSnapshotLogging(array $messages, int $omittedLines): LoadJobSnapshot
    {
        return new LoadJobSnapshot(
            id: $this->aJobId(),
            status: LoadJobStatus::Running,
            dumpPath: $this->aDump()->path,
            dumpName: $this->aDump()->name,
            dumpSize: 1024,
            options: LoadOptions::none(),
            requester: Requester::unknown(),
            steps: $this->plannedSteps(),
            progress: Progress::notStarted(2),
            log: ExecutionLog::fromLines(
                array_map(fn (string $message): LogLine => new LogLine($this->clock->now(), LogTag::Run, $message), $messages),
                $omittedLines,
            ),
            requestedAt: $this->clock->now(),
            startedAt: $this->clock->now(),
            finishedAt: null,
            failureReason: null,
        );
    }

    /**
     * @param list<LogLine> $lines
     *
     * @return list<string>
     */
    private function messages(array $lines): array
    {
        return array_map(static fn (LogLine $line): string => $line->message, $lines);
    }
}
