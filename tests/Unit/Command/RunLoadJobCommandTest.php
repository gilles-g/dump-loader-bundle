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

namespace DumpLoader\Tests\Unit\Command;

use DumpLoader\Command\RunLoadJobCommand;
use DumpLoader\Dump\Dump;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\FileSize;
use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\PlannedStep;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\RunLoadJobHandler;
use DumpLoader\LoadJob\StepStatus;
use DumpLoader\Step\StepSequence;
use DumpLoader\Tests\Double\FakeStep;
use DumpLoader\Tests\Double\InMemoryLoadJobRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RunLoadJobCommandTest extends TestCase
{
    #[Test]
    public function it_should_run_the_requested_job_and_report_its_completion(): void
    {
        $jobs = new InMemoryLoadJobRepository();
        $id = $this->aPendingJob($jobs);

        $tester = new CommandTester($this->command($jobs));
        $exitCode = $tester->execute(['id' => $id->value]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('is over', $tester->getDisplay());
        self::assertSame(LoadJobStatus::Completed, $jobs->get($id)->snapshot()->status);
    }

    #[Test]
    public function it_should_fail_when_the_job_does_not_exist(): void
    {
        $tester = new CommandTester($this->command(new InMemoryLoadJobRepository()));
        $exitCode = $tester->execute(['id' => '01J8Z9P0000000000000000000']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('01J8Z9P0000000000000000000', $tester->getDisplay());
    }

    #[Test]
    public function it_should_fail_when_the_identifier_is_not_a_valid_ulid(): void
    {
        $tester = new CommandTester($this->command(new InMemoryLoadJobRepository()));

        self::assertSame(Command::FAILURE, $tester->execute(['id' => 'not-an-ulid']));
    }

    private function command(InMemoryLoadJobRepository $jobs): RunLoadJobCommand
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $lock = $this->createStub(JobLock::class);
        $lock->method('acquire')->willReturn(true);

        $dumps = $this->createStub(DumpSource::class);
        $dumps->method('find')->willReturn($this->aDump());

        return new RunLoadJobCommand(new RunLoadJobHandler(
            jobs: $jobs,
            steps: new StepSequence([FakeStep::named('restore_database', 100)]),
            dumps: $dumps,
            lock: $lock,
            abortSignal: $this->createStub(AbortSignal::class),
            clock: $clock,
        ));
    }

    private function aPendingJob(InMemoryLoadJobRepository $jobs): LoadJobId
    {
        $job = LoadJob::request(
            id: LoadJobId::generate(),
            dump: $this->aDump(),
            options: LoadOptions::none(),
            steps: [new PlannedStep(name: 'restore_database', label: 'Restore database', weight: 10, status: StepStatus::Todo)],
            requester: Requester::unknown(),
            clock: new MockClock('2026-09-18 08:00:00'),
        );

        $jobs->save($job);

        return $job->id();
    }

    private function aDump(): Dump
    {
        return Dump::fromPath(
            'dumps/prod.sql.gz',
            FileSize::fromBytes(1024),
            new \DateTimeImmutable('2026-09-18 07:00:00'),
        );
    }
}
