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

use DumpLoader\LoadJob\InvalidTransition;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Percentage;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\StepStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class LoadJobTest extends TestCase
{
    use JobFixtures;

    private \DateTimeImmutable $now;

    private ClockInterface $clock;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-18 10:00:00');

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturnCallback(fn (): \DateTimeImmutable => $this->now);

        $this->clock = $clock;
    }

    #[Test]
    public function it_should_be_pending_and_know_what_it_was_asked_to_do_once_requested(): void
    {
        $snapshot = $this->requestedJob()->snapshot();

        self::assertSame(LoadJobStatus::Pending, $snapshot->status);
        self::assertTrue($snapshot->id->equals($this->aJobId()));
        self::assertSame('dumps/prod-2026-09-18.sql.gz', $snapshot->dumpPath);
        self::assertSame('prod-2026-09-18.sql.gz', $snapshot->dumpName);
        self::assertSame(1_048_576, $snapshot->dumpSize);
        self::assertSame(['clear_caches'], $snapshot->options->names);
        self::assertSame('alice', $snapshot->requester->name);
        self::assertEquals($this->now, $snapshot->requestedAt);
        self::assertNull($snapshot->startedAt);
        self::assertNull($snapshot->finishedAt);
        self::assertNull($snapshot->failureReason);
    }

    #[Test]
    public function it_should_have_every_step_still_to_do_and_no_progress_once_requested(): void
    {
        $snapshot = $this->requestedJob()->snapshot();

        self::assertCount(2, $snapshot->steps);
        self::assertSame('restore_database', $snapshot->steps[0]->name);
        self::assertSame(StepStatus::Todo, $snapshot->steps[0]->status);
        self::assertSame(StepStatus::Todo, $snapshot->steps[1]->status);
        self::assertSame(0, $snapshot->progress->stepNumber);
        self::assertSame(2, $snapshot->progress->stepCount);
    }

    #[Test]
    public function it_should_open_its_log_with_a_queued_line_once_requested(): void
    {
        $snapshot = $this->requestedJob()->snapshot();

        self::assertCount(1, $snapshot->log->lines);
        self::assertSame(LogTag::Queued, $snapshot->log->lines[0]->tag);
        self::assertEquals($this->now, $snapshot->log->lines[0]->at);
    }

    #[Test]
    public function it_should_record_when_it_began(): void
    {
        $job = $this->requestedJob();
        $this->now = $this->now->modify('+5 seconds');

        $job->start();

        $snapshot = $job->snapshot();
        self::assertSame(LoadJobStatus::Running, $snapshot->status);
        self::assertEquals($this->now, $snapshot->startedAt);
        self::assertNull($snapshot->finishedAt);
    }

    #[Test]
    public function it_should_move_the_progress_and_mark_the_step_running_when_starting_a_step(): void
    {
        $job = $this->runningJob();

        $job->startStep('restore_database');

        $snapshot = $job->snapshot();
        self::assertSame(StepStatus::Running, $snapshot->steps[0]->status);
        self::assertSame(1, $snapshot->progress->stepNumber);
        self::assertSame(2, $snapshot->progress->stepCount);
        self::assertSame(0.0, $snapshot->progress->stepPercentage->value);
    }

    #[Test]
    public function it_should_update_the_current_step_percentage_when_progressing(): void
    {
        $job = $this->runningJob();
        $job->startStep('restore_database');

        $job->progress(Percentage::of(40));

        $snapshot = $job->snapshot();
        self::assertSame(40.0, $snapshot->progress->stepPercentage->value);
        self::assertSame(20.0, $snapshot->progress->overall()->value);
    }

    #[Test]
    public function it_should_mark_a_step_done_when_completing_it(): void
    {
        $job = $this->runningJob();
        $job->startStep('restore_database');

        $job->completeStep();

        $snapshot = $job->snapshot();
        self::assertSame(StepStatus::Done, $snapshot->steps[0]->status);
        self::assertSame(StepStatus::Todo, $snapshot->steps[1]->status);
    }

    #[Test]
    public function it_should_time_each_step_with_its_clock(): void
    {
        $job = $this->runningJob();
        $job->startStep('restore_database');
        $this->now = $this->now->modify('+112 seconds');

        $job->completeStep();

        $step = $job->snapshot()->steps[0];
        self::assertEquals(new \DateTimeImmutable('2026-09-18 10:00:00'), $step->startedAt);
        self::assertEquals($this->now, $step->finishedAt);
        self::assertSame(112, $step->elapsedAt($this->now->modify('+1 hour'))?->seconds);
    }

    #[Test]
    public function it_should_stop_the_clock_of_the_step_it_was_running_when_it_ends_badly(): void
    {
        $failed = $this->runningJob();
        $failed->startStep('restore_database');
        $aborted = $this->runningJob();
        $aborted->startStep('restore_database');
        $lost = $this->runningJob();
        $lost->startStep('restore_database');
        $this->now = $this->now->modify('+4 seconds');

        $failed->fail('mysql exited with code 1');
        $aborted->abort();
        $lost->markLost();

        self::assertSame(4, $failed->snapshot()->steps[0]->elapsedAt($this->now->modify('+1 hour'))?->seconds);
        self::assertSame(4, $aborted->snapshot()->steps[0]->elapsedAt($this->now->modify('+1 hour'))?->seconds);
        self::assertSame(4, $lost->snapshot()->steps[0]->elapsedAt($this->now->modify('+1 hour'))?->seconds);
    }

    #[Test]
    public function it_should_record_when_it_ended_and_leave_nothing_pending_when_completed(): void
    {
        $job = $this->runningJob();
        $job->startStep('restore_database');
        $job->completeStep();
        $job->startStep('clear_caches');
        $job->completeStep();
        $this->now = $this->now->modify('+2 minutes');

        $job->complete();

        $snapshot = $job->snapshot();
        self::assertSame(LoadJobStatus::Completed, $snapshot->status);
        self::assertEquals($this->now, $snapshot->finishedAt);
        self::assertSame(100.0, $snapshot->progress->overall()->value);
        self::assertSame(LogTag::Done, $snapshot->log->last()?->tag);
    }

    #[Test]
    public function it_should_append_to_the_journal_when_logging(): void
    {
        $job = $this->runningJob();

        $job->log(LogTag::Error, 'ERROR 1045 (28000): Access denied');

        $last = $job->snapshot()->log->last();
        self::assertNotNull($last);
        self::assertSame(LogTag::Error, $last->tag);
        self::assertSame('ERROR 1045 (28000): Access denied', $last->message);
        self::assertEquals($this->now, $last->at);
    }

    #[Test]
    public function it_should_keep_the_reason_and_mark_the_current_step_failed_when_failing(): void
    {
        $job = $this->runningJob();
        $job->startStep('restore_database');

        $job->fail('mysql exited with code 1');

        $snapshot = $job->snapshot();
        self::assertSame(LoadJobStatus::Failed, $snapshot->status);
        self::assertSame('mysql exited with code 1', $snapshot->failureReason);
        self::assertSame(StepStatus::Failed, $snapshot->steps[0]->status);
        self::assertEquals($this->now, $snapshot->finishedAt);
    }

    #[Test]
    public function it_should_be_able_to_fail_before_it_ever_started(): void
    {
        $job = $this->requestedJob();

        $job->fail('the launcher could not spawn a process');

        self::assertSame(LoadJobStatus::Failed, $job->snapshot()->status);
        self::assertSame(StepStatus::Todo, $job->snapshot()->steps[0]->status);
    }

    #[Test]
    public function it_should_stop_the_current_step_when_aborting(): void
    {
        $job = $this->runningJob();
        $job->startStep('restore_database');

        $job->abort();

        $snapshot = $job->snapshot();
        self::assertSame(LoadJobStatus::Aborted, $snapshot->status);
        self::assertSame(StepStatus::Aborted, $snapshot->steps[0]->status);
        self::assertEquals($this->now, $snapshot->finishedAt);
        self::assertSame(LogTag::Stop, $snapshot->log->last()?->tag);
    }

    #[Test]
    public function it_should_be_able_to_be_aborted_before_it_ever_started(): void
    {
        $job = $this->requestedJob();

        $job->abort();

        self::assertSame(LoadJobStatus::Aborted, $job->snapshot()->status);
    }

    #[Test]
    public function it_should_be_lost_when_its_process_vanished_while_running(): void
    {
        $job = $this->runningJob();
        $job->startStep('restore_database');

        $job->markLost();

        $snapshot = $job->snapshot();
        self::assertSame(LoadJobStatus::Lost, $snapshot->status);
        self::assertSame(StepStatus::Aborted, $snapshot->steps[0]->status);
        self::assertEquals($this->now, $snapshot->finishedAt);
    }

    #[Test]
    public function it_should_be_lost_when_pending_and_never_picked_up(): void
    {
        $job = $this->requestedJob();

        $job->markLost();

        self::assertSame(LoadJobStatus::Lost, $job->snapshot()->status);
    }

    #[Test]
    public function it_should_not_start_twice(): void
    {
        $job = $this->runningJob();

        $this->expectException(InvalidTransition::class);

        $job->start();
    }

    #[Test]
    public function it_should_not_run_a_step_while_pending(): void
    {
        $job = $this->requestedJob();

        $this->expectException(InvalidTransition::class);

        $job->startStep('restore_database');
    }

    #[Test]
    public function it_should_not_complete_while_pending(): void
    {
        $job = $this->requestedJob();

        $this->expectException(InvalidTransition::class);

        $job->complete();
    }

    #[Test]
    public function it_should_refuse_to_progress_without_a_running_step(): void
    {
        $job = $this->runningJob();

        $this->expectException(InvalidTransition::class);

        $job->progress(Percentage::of(10));
    }

    #[Test]
    public function it_should_refuse_to_complete_a_step_that_was_never_started(): void
    {
        $job = $this->runningJob();

        $this->expectException(InvalidTransition::class);

        $job->completeStep();
    }

    #[Test]
    public function it_should_refuse_to_start_a_step_that_was_not_planned(): void
    {
        $job = $this->runningJob();

        $this->expectException(InvalidTransition::class);

        $job->startStep('anonymize_data');
    }

    #[Test]
    public function it_should_refuse_to_start_a_step_it_has_already_reached(): void
    {
        $job = $this->runningJob();
        $job->startStep('restore_database');

        $this->expectException(InvalidTransition::class);

        $job->startStep('restore_database');
    }

    #[Test]
    public function it_should_complete_a_job_that_was_planned_without_any_step(): void
    {
        $job = LoadJob::request(
            id: $this->aJobId(),
            dump: $this->aDump(),
            options: LoadOptions::none(),
            steps: [],
            requester: Requester::unknown(),
            clock: $this->clock,
        );
        $job->start();
        $job->complete();

        $snapshot = $job->snapshot();

        self::assertSame(LoadJobStatus::Completed, $snapshot->status);
        self::assertSame(0, $snapshot->progress->stepCount);
    }

    #[Test]
    public function it_should_refuse_every_transition_once_completed(): void
    {
        $job = $this->runningJob();
        $job->startStep('restore_database');
        $job->completeStep();
        $job->startStep('clear_caches');
        $job->completeStep();
        $job->complete();

        $this->assertRefusesEveryTransition($job);
    }

    #[Test]
    public function it_should_refuse_every_transition_once_failed(): void
    {
        $job = $this->runningJob();
        $job->fail('mysql exited with code 1');

        $this->assertRefusesEveryTransition($job);
    }

    #[Test]
    public function it_should_refuse_every_transition_once_aborted(): void
    {
        $job = $this->runningJob();
        $job->abort();

        $this->assertRefusesEveryTransition($job);
    }

    #[Test]
    public function it_should_refuse_every_transition_once_lost(): void
    {
        $job = $this->runningJob();
        $job->markLost();

        $this->assertRefusesEveryTransition($job);
    }

    #[Test]
    public function it_should_be_rebuilt_from_its_snapshot(): void
    {
        $job = $this->runningJob();
        $job->startStep('restore_database');
        $job->progress(Percentage::of(70));

        $rebuilt = LoadJob::fromSnapshot($job->snapshot(), $this->clock);
        $rebuilt->completeStep();

        self::assertSame(StepStatus::Done, $rebuilt->snapshot()->steps[0]->status);
        self::assertSame(LoadJobStatus::Running, $rebuilt->snapshot()->status);
    }

    private function assertRefusesEveryTransition(LoadJob $job): void
    {
        $transitions = [
            'start' => static fn (LoadJob $job) => $job->start(),
            'startStep' => static fn (LoadJob $job) => $job->startStep('restore_database'),
            'progress' => static fn (LoadJob $job) => $job->progress(Percentage::of(10)),
            'completeStep' => static fn (LoadJob $job) => $job->completeStep(),
            'log' => static fn (LoadJob $job) => $job->log(LogTag::Run, 'still alive'),
            'complete' => static fn (LoadJob $job) => $job->complete(),
            'fail' => static fn (LoadJob $job) => $job->fail('too late'),
            'abort' => static fn (LoadJob $job) => $job->abort(),
            'markLost' => static fn (LoadJob $job) => $job->markLost(),
        ];

        $refused = [];
        foreach ($transitions as $name => $transition) {
            try {
                $transition($job);
            } catch (InvalidTransition) {
                $refused[] = $name;
            }
        }

        self::assertSame(
            array_keys($transitions),
            $refused,
            \sprintf('a %s job must refuse every transition.', $job->snapshot()->status->value),
        );
    }

    private function requestedJob(): LoadJob
    {
        return LoadJob::request(
            id: $this->aJobId(),
            dump: $this->aDump(),
            options: LoadOptions::fromNames(['clear_caches']),
            steps: $this->plannedSteps(),
            requester: Requester::named('alice'),
            clock: $this->clock,
        );
    }

    private function runningJob(): LoadJob
    {
        $job = $this->requestedJob();
        $job->start();

        return $job;
    }
}
