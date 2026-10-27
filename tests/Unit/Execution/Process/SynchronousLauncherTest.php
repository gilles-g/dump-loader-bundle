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

namespace DumpLoader\Tests\Unit\Execution\Process;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\FileSize;
use DumpLoader\Execution\LoadJobLauncher;
use DumpLoader\Execution\Process\SynchronousLauncher;
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
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepSequence;
use DumpLoader\Tests\Double\FakeStep;
use DumpLoader\Tests\Double\InMemoryLoadJobRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SynchronousLauncherTest extends TestCase
{
    #[Test]
    public function it_should_be_a_launcher(): void
    {
        self::assertInstanceOf(LoadJobLauncher::class, $this->launcher(new InMemoryLoadJobRepository(), FakeStep::named('restore_database', 100)));
    }

    #[Test]
    public function it_should_run_the_job_in_the_calling_process(): void
    {
        $id = LoadJobId::fromString('01J8Z9P0000000000000000000');
        $clock = new MockClock('2026-09-18 08:00:00');

        $jobs = new InMemoryLoadJobRepository();
        $jobs->save(LoadJob::request(
            $id,
            Dump::fromPath('dumps/prod.sql', FileSize::fromBytes(1024), new \DateTimeImmutable('2026-09-18 08:00:00')),
            LoadOptions::none(),
            [new PlannedStep('restore_database', 'Restore database', 10, StepStatus::Todo)],
            Requester::unknown(),
            $clock,
        ));

        $step = FakeStep::named('restore_database', StepDefinition::RANK_RESTORE_DATABASE);

        $this->launcher($jobs, $step)->launch($id);

        self::assertSame([$id->value], $step->ranFor);
        self::assertSame(LoadJobStatus::Completed, $jobs->get($id)->snapshot()->status);
    }

    private function launcher(InMemoryLoadJobRepository $jobs, FakeStep $step): SynchronousLauncher
    {
        $clock = new MockClock('2026-09-18 08:00:00');

        $dumps = $this->createStub(DumpSource::class);
        $dumps->method('find')->willReturn(
            Dump::fromPath('dumps/prod.sql', FileSize::fromBytes(1024), new \DateTimeImmutable('2026-09-18 08:00:00')),
        );

        $lock = $this->createStub(JobLock::class);
        $lock->method('acquire')->willReturn(true);

        return new SynchronousLauncher(new RunLoadJobHandler(
            jobs: $jobs,
            steps: new StepSequence([$step]),
            dumps: $dumps,
            lock: $lock,
            abortSignal: $this->createStub(AbortSignal::class),
            clock: $clock,
        ));
    }
}
