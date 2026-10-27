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

use DumpLoader\Database\RestorePolicy;
use DumpLoader\Dump\CorruptedDump;
use DumpLoader\Dump\DumpNotFound;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\SqlInput;
use DumpLoader\Execution\LoadJobLauncher;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJobAlreadyRunning;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobNotFound;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadJobSnapshot;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\PlannedStep;
use DumpLoader\LoadJob\ReconcileLostJobs;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\RequestLoadJob;
use DumpLoader\LoadJob\RequestLoadJobHandler;
use DumpLoader\LoadJob\UnreadableLoadJob;
use DumpLoader\LoadJob\VanishedJobs;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepSequence;
use DumpLoader\Tests\Double\FakeOptionalStep;
use DumpLoader\Tests\Double\FakeStep;
use DumpLoader\Tests\Double\InMemoryLoadJobRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class RequestLoadJobHandlerTest extends TestCase
{
    use JobFixtures;

    #[Test]
    public function it_should_plan_the_required_step_and_the_checked_options_only(): void
    {
        $jobs = new InMemoryLoadJobRepository();

        $id = ($this->handler($jobs))(new RequestLoadJob(
            'dumps/prod-2026-09-18.sql.gz',
            LoadOptions::fromNames(['clear_caches']),
            Requester::named('alice'),
        ));

        $snapshot = $jobs->get($id)->snapshot();

        self::assertSame(LoadJobStatus::Pending, $snapshot->status);
        self::assertSame('alice', $snapshot->requester->name);
        self::assertSame(
            ['restore_database', 'clear_caches'],
            array_map(static fn (PlannedStep $step): string => $step->name, $snapshot->steps),
        );
    }

    #[Test]
    public function it_should_save_the_job_before_launching_it(): void
    {
        $jobs = new InMemoryLoadJobRepository();

        $launcher = $this->createMock(LoadJobLauncher::class);
        $launcher->expects(self::once())->method('launch')->willReturnCallback(
            static function (LoadJobId $id) use ($jobs): void {
                self::assertSame(LoadJobStatus::Pending, $jobs->get($id)->snapshot()->status);
            },
        );

        ($this->handler($jobs, launcher: $launcher))($this->aRequest());
    }

    #[Test]
    public function it_should_refuse_a_second_job_while_one_is_active(): void
    {
        $jobs = new InMemoryLoadJobRepository();
        $handler = $this->handler($jobs);

        $handler($this->aRequest());

        $this->expectException(LoadJobAlreadyRunning::class);

        $handler($this->aRequest());
    }

    #[Test]
    public function it_should_reconcile_lost_jobs_before_checking_for_an_active_one(): void
    {
        $jobs = new InMemoryLoadJobRepository();
        $handler = $this->handler($jobs, lockIsFree: true, pendingGracePeriod: 0);

        $first = $handler($this->aRequest());
        $second = $handler($this->aRequest());

        self::assertSame(LoadJobStatus::Lost, $jobs->get($first)->snapshot()->status);
        self::assertSame(LoadJobStatus::Pending, $jobs->get($second)->snapshot()->status);
    }

    #[Test]
    public function it_should_forget_the_finished_jobs_beyond_the_retention(): void
    {
        $jobs = new InMemoryLoadJobRepository();
        $handler = $this->handler($jobs, lockIsFree: true, pendingGracePeriod: 0, retention: 1);

        $first = $handler($this->aRequest());
        $second = $handler($this->aRequest());
        $third = $handler($this->aRequest());

        self::assertSame(
            [$third->value, $second->value],
            array_map(static fn (LoadJobSnapshot $snapshot): string => $snapshot->id->value, iterator_to_array($jobs->history(10))),
        );

        $this->expectException(LoadJobNotFound::class);
        $jobs->get($first);
    }

    #[Test]
    public function it_should_refuse_a_new_job_while_a_job_file_is_unreadable(): void
    {
        $jobs = $this->createMock(LoadJobRepository::class);
        $jobs->method('active')->willThrowException(UnreadableLoadJob::at('jobs/01J8Z9P0000000000000000001.json', 'Syntax error'));
        $jobs->expects(self::never())->method('save');

        $launcher = $this->createMock(LoadJobLauncher::class);
        $launcher->expects(self::never())->method('launch');

        $this->expectException(UnreadableLoadJob::class);

        ($this->handler($jobs, launcher: $launcher))($this->aRequest());
    }

    #[Test]
    public function it_should_fail_the_job_when_the_launch_blows_up(): void
    {
        $jobs = new InMemoryLoadJobRepository();

        $launcher = $this->createStub(LoadJobLauncher::class);
        $launcher->method('launch')->willThrowException(new \RuntimeException('php binary not found'));

        try {
            ($this->handler($jobs, launcher: $launcher))($this->aRequest());
            self::fail('The launch failure should have been rethrown.');
        } catch (\RuntimeException $exception) {
            self::assertSame('php binary not found', $exception->getMessage());
        }

        $snapshot = $jobs->latest();

        self::assertSame(LoadJobStatus::Failed, $snapshot->status);
        self::assertSame('php binary not found', $snapshot->failureReason);
    }

    #[Test]
    public function it_should_refuse_an_unknown_dump(): void
    {
        $dumps = $this->createStub(DumpSource::class);
        $dumps->method('find')->willThrowException(DumpNotFound::atPath('dumps/nope.sql'));

        $this->expectException(DumpNotFound::class);

        ($this->handler(new InMemoryLoadJobRepository(), dumps: $dumps))($this->aRequest());
    }

    #[Test]
    public function it_should_check_the_requested_dump_can_be_restored(): void
    {
        $input = $this->createStub(SqlInput::class);

        $dumpSource = $this->createStub(DumpSource::class);
        $dumpSource->method('find')->willReturn($this->aDump());
        $dumpSource->method('open')->willReturn($input);

        $restorePolicy = $this->createMock(RestorePolicy::class);
        $restorePolicy->expects(self::once())->method('ensureRestorable')->with($input);

        ($this->handler(new InMemoryLoadJobRepository(), dumps: $dumpSource, restorePolicy: $restorePolicy))($this->aRequest());
    }

    #[Test]
    public function it_should_refuse_a_dump_that_cannot_be_restored_without_planning_a_job(): void
    {
        $jobs = new InMemoryLoadJobRepository();

        $restorePolicy = $this->createStub(RestorePolicy::class);
        $restorePolicy->method('ensureRestorable')->willThrowException(CorruptedDump::undecodable());

        $loadJobLauncher = $this->createMock(LoadJobLauncher::class);
        $loadJobLauncher->expects(self::never())->method('launch');

        try {
            ($this->handler($jobs, launcher: $loadJobLauncher, restorePolicy: $restorePolicy))($this->aRequest());
            self::fail('The dump should have been refused.');
        } catch (CorruptedDump) {
        }

        self::assertCount(0, $jobs->history(10));
    }

    private function aRequest(): RequestLoadJob
    {
        return new RequestLoadJob('dumps/prod-2026-09-18.sql.gz', LoadOptions::none(), Requester::unknown());
    }

    private function handler(
        LoadJobRepository $jobs,
        ?DumpSource $dumps = null,
        ?LoadJobLauncher $launcher = null,
        bool $lockIsFree = false,
        int $pendingGracePeriod = 60,
        ?RestorePolicy $restorePolicy = null,
        int $retention = 50,
    ): RequestLoadJobHandler {
        $clock = new MockClock('2026-09-18 08:00:00');
        $lock = $this->jobLock($lockIsFree);

        return new RequestLoadJobHandler(
            dumps: $dumps ?? $this->dumpSource(),
            restorePolicy: $restorePolicy ?? $this->createStub(RestorePolicy::class),
            steps: new StepSequence([
                FakeOptionalStep::named('clear_caches', StepDefinition::RANK_CLEAR_CACHES, 1),
                FakeStep::named('restore_database', StepDefinition::RANK_RESTORE_DATABASE),
            ]),
            jobs: $jobs,
            reconcileLostJobs: new ReconcileLostJobs($jobs, new VanishedJobs($lock, $clock, $pendingGracePeriod), $lock),
            launcher: $launcher ?? $this->createStub(LoadJobLauncher::class),
            locks: new LockFactory(new InMemoryStore()),
            clock: $clock,
            retention: $retention,
        );
    }

    private function dumpSource(): DumpSource
    {
        $dumps = $this->createStub(DumpSource::class);
        $dumps->method('find')->willReturn($this->aDump());

        return $dumps;
    }

    private function jobLock(bool $isFree): JobLock
    {
        $lock = $this->createStub(JobLock::class);
        $lock->method('isFree')->willReturn($isFree);
        $lock->method('acquire')->willReturn(true);

        return $lock;
    }
}
