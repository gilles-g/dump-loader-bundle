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

use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\Dump;
use DumpLoader\Dump\Dumps;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\FileSize;
use DumpLoader\LoadJob\DashboardQuery;
use DumpLoader\LoadJob\ExecutionLog;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadJobs;
use DumpLoader\LoadJob\LoadJobSnapshot;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\Progress;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\UnreadableLoadJob;
use DumpLoader\LoadJob\VanishedJobs;
use DumpLoader\Step\OptionalStep;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepSequence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class DashboardQueryTest extends TestCase
{
    #[Test]
    public function it_should_show_the_available_dumps_and_the_target_database(): void
    {
        $dashboard = $this->query(
            dumps: new Dumps($this->aDump('dumps/prod.sql.gz')),
        )->current();

        self::assertCount(1, $dashboard->dumps);
        self::assertSame('db.internal:3306/app', $dashboard->targetDatabase);
    }

    #[Test]
    public function it_should_still_show_the_dashboard_when_the_target_database_dsn_is_unreadable(): void
    {
        $dashboard = $this->query(
            dumps: new Dumps($this->aDump('dumps/prod.sql.gz')),
            targetDatabaseDsn: new TargetDatabaseDsn('not-a-dsn'),
        )->current();

        self::assertNull($dashboard->targetDatabase);
        self::assertCount(1, $dashboard->dumps);
    }

    #[Test]
    public function it_should_expose_optional_steps_as_definitions_and_pre_check_the_default_ones(): void
    {
        $dashboard = $this->query(
            steps: new StepSequence([
                $this->anOptionalStep('run_migrations', StepDefinition::RANK_RUN_MIGRATIONS, true),
                $this->anOptionalStep('clear_caches', StepDefinition::RANK_CLEAR_CACHES, false),
            ]),
        )->current();

        self::assertSame(
            ['run_migrations', 'clear_caches'],
            array_map(static fn (StepDefinition $definition): string => $definition->name, $dashboard->availableOptions),
        );
        self::assertSame(['run_migrations'], $dashboard->defaultOptions->names);
    }

    #[Test]
    public function it_should_show_no_active_job_when_none_is_running(): void
    {
        $dashboard = $this->query()->current();

        self::assertNull($dashboard->activeJob);
        self::assertNull($dashboard->unreadableJobDetail);
    }

    #[Test]
    public function it_should_show_the_first_active_job_the_repository_reports(): void
    {
        $running = $this->aSnapshot('01J8Z9P0000000000000000001', LoadJobStatus::Running, null);

        $dashboard = $this->query(active: new LoadJobs($running))->current();

        self::assertNotNull($dashboard->activeJob);
        self::assertSame('01J8Z9P0000000000000000001', $dashboard->activeJob->id->value);
    }

    #[Test]
    public function it_should_report_an_unreadable_job_instead_of_guessing_there_is_no_active_one(): void
    {
        $jobs = $this->createStub(LoadJobRepository::class);
        $jobs->method('active')->willThrowException(UnreadableLoadJob::at('jobs/01J8Z9P0000000000000000001.json', 'Syntax error'));
        $jobs->method('history')->willReturn(new LoadJobs(
            $this->aSnapshot('01J8Z9P0000000000000000002', LoadJobStatus::Completed, new \DateTimeImmutable('2026-09-17 10:00:00')),
        ));

        $dashboard = (new DashboardQuery(
            dumps: $this->aDumpSource(new Dumps()),
            steps: new StepSequence([]),
            jobs: $jobs,
            vanished: $this->noVanishedJob(),
            targetDatabaseDsn: $this->aTargetDsn(),
            historyLimit: 20,
        ))->current();

        self::assertNull($dashboard->activeJob);
        self::assertSame('Cannot read the load job stored at "jobs/01J8Z9P0000000000000000001.json": Syntax error', $dashboard->unreadableJobDetail);
        self::assertSame(1, $dashboard->completedCount);
    }

    #[Test]
    public function it_should_compute_the_key_indicators_from_the_history(): void
    {
        $dashboard = $this->query(history: new LoadJobs(
            $this->aSnapshot('01J8Z9P0000000000000000002', LoadJobStatus::Completed, new \DateTimeImmutable('2026-09-17 10:00:00')),
            $this->aSnapshot('01J8Z9P0000000000000000003', LoadJobStatus::Failed, new \DateTimeImmutable('2026-09-18 09:00:00')),
            $this->aSnapshot('01J8Z9P0000000000000000004', LoadJobStatus::Completed, new \DateTimeImmutable('2026-09-18 11:00:00')),
            $this->aSnapshot('01J8Z9P0000000000000000005', LoadJobStatus::Aborted, new \DateTimeImmutable('2026-09-18 12:00:00')),
        ))->current();

        self::assertSame(2, $dashboard->completedCount);
        self::assertSame(1, $dashboard->failedCount);
        self::assertNotNull($dashboard->lastCompletedAt);
        self::assertSame('2026-09-18 11:00:00', $dashboard->lastCompletedAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_should_show_no_last_successful_load_when_nothing_ever_completed(): void
    {
        $dashboard = $this->query(history: new LoadJobs(
            $this->aSnapshot('01J8Z9P0000000000000000006', LoadJobStatus::Failed, new \DateTimeImmutable('2026-09-18 09:00:00')),
        ))->current();

        self::assertNull($dashboard->lastCompletedAt);
        self::assertSame(0, $dashboard->completedCount);
    }

    #[Test]
    public function it_should_ask_the_history_with_the_configured_limit(): void
    {
        $jobs = $this->createMock(LoadJobRepository::class);
        $jobs->method('active')->willReturn(new LoadJobs());
        $jobs->expects(self::once())->method('history')->with(5)->willReturn(new LoadJobs());

        $query = new DashboardQuery(
            dumps: $this->aDumpSource(new Dumps()),
            steps: new StepSequence([]),
            jobs: $jobs,
            vanished: $this->noVanishedJob(),
            targetDatabaseDsn: $this->aTargetDsn(),
            historyLimit: 5,
        );

        self::assertCount(0, $query->current()->history);
    }

    private function query(
        ?Dumps $dumps = null,
        ?StepSequence $steps = null,
        ?LoadJobs $active = null,
        ?LoadJobs $history = null,
        ?TargetDatabaseDsn $targetDatabaseDsn = null,
    ): DashboardQuery {
        $jobs = $this->createStub(LoadJobRepository::class);
        $jobs->method('active')->willReturn($active ?? new LoadJobs());
        $jobs->method('history')->willReturn($history ?? new LoadJobs());

        return new DashboardQuery(
            dumps: $this->aDumpSource($dumps ?? new Dumps()),
            steps: $steps ?? new StepSequence([]),
            jobs: $jobs,
            vanished: $this->noVanishedJob(),
            targetDatabaseDsn: $targetDatabaseDsn ?? $this->aTargetDsn(),
            historyLimit: 20,
        );
    }

    private function noVanishedJob(): VanishedJobs
    {
        $lock = $this->createStub(JobLock::class);
        $lock->method('isFree')->willReturn(false);

        return new VanishedJobs($lock, new MockClock('2026-09-18 08:00:00'), 60);
    }

    private function aDumpSource(Dumps $dumps): DumpSource
    {
        $source = $this->createStub(DumpSource::class);
        $source->method('list')->willReturn($dumps);

        return $source;
    }

    private function aTargetDsn(): TargetDatabaseDsn
    {
        return new TargetDatabaseDsn('mysql://root:secret@db.internal:3306/app');
    }

    private function aDump(string $path): Dump
    {
        return Dump::fromPath($path, FileSize::fromBytes(1024), new \DateTimeImmutable('2026-09-18 08:00:00'));
    }

    private function anOptionalStep(string $name, int $rank, bool $enabledByDefault): OptionalStep
    {
        $step = $this->createStub(OptionalStep::class);
        $step->method('definition')->willReturn(new StepDefinition(
            name: $name,
            label: \sprintf('step.%s.label', $name),
            description: \sprintf('step.%s.description', $name),
            rank: $rank,
            weight: 1,
        ));
        $step->method('enabledByDefault')->willReturn($enabledByDefault);

        return $step;
    }

    private function aSnapshot(string $id, LoadJobStatus $status, ?\DateTimeImmutable $finishedAt): LoadJobSnapshot
    {
        return new LoadJobSnapshot(
            id: LoadJobId::fromString($id),
            status: $status,
            dumpPath: 'dumps/prod.sql.gz',
            dumpName: 'prod.sql.gz',
            dumpSize: 1024,
            options: LoadOptions::none(),
            requester: Requester::unknown(),
            steps: [],
            progress: Progress::notStarted(0),
            log: ExecutionLog::empty(),
            requestedAt: new \DateTimeImmutable('2026-09-18 08:00:00'),
            startedAt: null,
            finishedAt: $finishedAt,
            failureReason: null,
        );
    }
}
