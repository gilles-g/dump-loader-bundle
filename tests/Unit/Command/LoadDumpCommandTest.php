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

use DumpLoader\Command\LoadDumpCommand;
use DumpLoader\Command\Screen\Screens;
use DumpLoader\Database\Mysql\MysqlBinary;
use DumpLoader\Database\RestorePolicy;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\CorruptedDump;
use DumpLoader\Dump\Dump;
use DumpLoader\Dump\DumpNotFound;
use DumpLoader\Dump\Dumps;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\FileSize;
use DumpLoader\Dump\SqlInput;
use DumpLoader\Execution\LoadJobLauncher;
use DumpLoader\Execution\Process\SynchronousLauncher;
use DumpLoader\LoadJob\AbortLoadJobHandler;
use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\DashboardQuery;
use DumpLoader\LoadJob\FollowLoadJobs;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\ReconcileLostJobs;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\RequestLoadJobHandler;
use DumpLoader\LoadJob\RunLoadJobHandler;
use DumpLoader\LoadJob\UnreadableLoadJob;
use DumpLoader\LoadJob\VanishedJobs;
use DumpLoader\Requirements\CheckRequirements;
use DumpLoader\Step\StepSequence;
use DumpLoader\Tests\Double\EnglishLabels;
use DumpLoader\Tests\Double\FakeOptionalStep;
use DumpLoader\Tests\Double\FakeStep;
use DumpLoader\Tests\Double\InMemoryLoadJobRepository;
use DumpLoader\Tests\Double\ScriptedClock;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Process\ExecutableFinder;

final class LoadDumpCommandTest extends TestCase
{
    private const SIGINT = 2;
    private const INTERRUPTED = 130;

    private InMemoryLoadJobRepository $jobs;

    private ScriptedClock $clock;

    private FakeStep $restore;

    protected function setUp(): void
    {
        $this->jobs = new InMemoryLoadJobRepository();
        $this->clock = new ScriptedClock();
        $this->restore = FakeStep::named('restore_database', 100);
    }

    #[Test]
    public function it_should_load_the_chosen_dump_with_the_chosen_options(): void
    {
        $tester = new CommandTester($this->command());
        $tester->setInputs(['dumps/staging.sql', 'no', 'yes', 'yes']);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame('dumps/staging.sql', $this->jobs->latest()->dumpPath);
        self::assertSame(['clear_caches'], $this->jobs->latest()->options->names);

        $display = $tester->getDisplay();
        self::assertStringContainsString('staging.sql (2.0 KB, 2026-09-17 22:00)', $display);
        self::assertStringContainsString('Every table of "127.0.0.1:3306/app" will be dropped', $display);
        self::assertStringContainsString('Clear_caches', $display);
        self::assertStringContainsString('The dump is loaded.', $display);
    }

    #[Test]
    public function it_should_load_with_the_default_options_without_interaction(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true], ['interactive' => false]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(['run_migrations'], $this->jobs->latest()->options->names);
        self::assertSame(LoadJobStatus::Completed, $this->jobs->latest()->status);
    }

    #[Test]
    public function it_should_run_only_the_options_given(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['dump' => 'dumps/prod.sql.gz', '--option' => ['clear_caches'], '--force' => true]);

        self::assertSame(['clear_caches'], $this->jobs->latest()->options->names);
    }

    #[Test]
    public function it_should_run_no_option_when_asked(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['dump' => 'dumps/prod.sql.gz', '--no-options' => true, '--force' => true]);

        self::assertSame([], $this->jobs->latest()->options->names);
    }

    #[Test]
    public function it_should_refuse_an_unknown_option(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute(['dump' => 'dumps/prod.sql.gz', '--option' => ['nope'], '--force' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('There is no option named "nope"; available options: run_migrations, clear_caches.', $this->flat($tester));
        self::assertTrue($this->jobs->history(10)->isEmpty());
    }

    #[Test]
    public function it_should_refuse_to_drop_the_tables_unasked_without_force(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute(['dump' => 'dumps/prod.sql.gz'], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('pass --force to load without being asked', $this->flat($tester));
        self::assertTrue($this->jobs->history(10)->isEmpty());
    }

    #[Test]
    public function it_should_refuse_to_choose_a_dump_without_interaction(): void
    {
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('pass the path of the dump to load', $this->flat($tester));
    }

    #[Test]
    public function it_should_stop_when_the_confirmation_is_declined(): void
    {
        $tester = new CommandTester($this->command());
        $tester->setInputs(['no']);

        $exitCode = $tester->execute(['dump' => 'dumps/prod.sql.gz', '--no-options' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('The load was cancelled before it started.', $this->flat($tester));
        self::assertTrue($this->jobs->history(10)->isEmpty());
    }

    #[Test]
    public function it_should_refuse_while_another_job_is_active(): void
    {
        $active = $this->aPendingJob();
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString($active->value, $this->flat($tester));
    }

    #[Test]
    public function it_should_refuse_while_the_active_job_cannot_be_read(): void
    {
        $jobs = $this->createStub(LoadJobRepository::class);
        $jobs->method('active')->willThrowException(UnreadableLoadJob::at('jobs/broken.json', 'Syntax error'));
        $jobs->method('history')->willReturn($this->jobs->history(10));

        $tester = new CommandTester($this->command(jobs: $jobs));

        self::assertSame(Command::FAILURE, $tester->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true]));
        self::assertStringContainsString('Syntax error', $this->flat($tester));
    }

    #[Test]
    public function it_should_refuse_when_there_is_no_dump(): void
    {
        $tester = new CommandTester($this->command(dumps: new Dumps()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('There is no dump to load.', $this->flat($tester));
    }

    #[Test]
    public function it_should_refuse_a_dump_that_is_not_listed(): void
    {
        $tester = new CommandTester($this->command());

        self::assertSame(Command::FAILURE, $tester->execute(['dump' => 'dumps/nope.sql', '--force' => true]));
        self::assertStringContainsString('No dump available at "dumps/nope.sql".', $this->flat($tester));
    }

    #[Test]
    public function it_should_refuse_a_dump_that_cannot_be_restored(): void
    {
        $restorePolicy = $this->createStub(RestorePolicy::class);
        $restorePolicy->method('ensureRestorable')->willThrowException(CorruptedDump::undecodable());

        $tester = new CommandTester($this->command(restorePolicy: $restorePolicy));

        self::assertSame(Command::FAILURE, $tester->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true]));
        self::assertStringContainsString('The dump cannot be restored', $this->flat($tester));
        self::assertTrue($this->jobs->history(10)->isEmpty());
    }

    #[Test]
    public function it_should_warn_about_unmet_requirements_and_go_on(): void
    {
        $tester = new CommandTester($this->command(console: '/nowhere/bin/console'));

        self::assertSame(Command::SUCCESS, $tester->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true]));
        self::assertStringContainsString('Some requirements are not met', $this->flat($tester));
        self::assertStringContainsString('Symfony console: "/nowhere/bin/console" does not exist.', $this->flat($tester));
    }

    #[Test]
    public function it_should_return_right_after_starting_a_detached_job(): void
    {
        $tester = new CommandTester($this->command(launcher: $this->createStub(LoadJobLauncher::class)));

        $exitCode = $tester->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true, '--detach' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString(\sprintf('Load job "%s" started, follow it in the monitor.', $this->jobs->latest()->id->value), $this->flat($tester));
        self::assertSame(LoadJobStatus::Pending, $this->jobs->latest()->status);
    }

    #[Test]
    public function it_should_follow_a_detached_job_until_it_completes(): void
    {
        $this->clock->onSleep(
            fn () => $this->onLatestJob(static function (LoadJob $job): void {
                $job->start();
                $job->startStep('restore_database');
            }),
            fn () => $this->onLatestJob(static function (LoadJob $job): void {
                $job->completeStep();
                $job->complete();
            }),
        );

        $tester = new CommandTester($this->command(launcher: $this->createStub(LoadJobLauncher::class)));

        $exitCode = $tester->execute(['dump' => 'dumps/prod.sql.gz', '--no-options' => true, '--force' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(2, $this->clock->sleeps());

        $display = $tester->getDisplay();
        self::assertStringContainsString('Load job queued for dump "prod.sql.gz".', $display);
        self::assertStringContainsString('Load job started.', $display);
        self::assertStringContainsString('Load job completed.', $display);
        self::assertStringContainsString('The dump is loaded.', $display);
    }

    #[Test]
    public function it_should_abort_the_followed_job_on_interrupt(): void
    {
        $abortSignal = $this->createMock(AbortSignal::class);
        $abortSignal->expects(self::once())->method('request');

        $command = $this->command(launcher: $this->createStub(LoadJobLauncher::class), abortSignal: $abortSignal);

        $this->clock->onSleep(
            static fn () => self::assertFalse($command->handleSignal(self::SIGINT)),
            fn () => $this->onLatestJob(static fn (LoadJob $job) => $job->abort()),
        );

        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['dump' => 'dumps/prod.sql.gz', '--no-options' => true, '--force' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Aborting…', $tester->getDisplay());
        self::assertStringContainsString('The load job was aborted on request.', $this->flat($tester));
    }

    #[Test]
    public function it_should_let_an_interrupt_stop_the_command_when_no_job_is_followed(): void
    {
        $command = $this->command();

        self::assertSame([self::SIGINT], $command->getSubscribedSignals());
        self::assertSame(self::INTERRUPTED, $command->handleSignal(self::SIGINT));
    }

    #[Test]
    public function it_should_let_an_interrupt_stop_the_command_once_the_job_is_over(): void
    {
        $command = $this->command();
        (new CommandTester($command))->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true]);

        self::assertSame(self::INTERRUPTED, $command->handleSignal(self::SIGINT));
    }

    #[Test]
    public function it_should_report_a_failed_job_and_fail(): void
    {
        $this->restore->failingWith(new \RuntimeException('mysql: access denied'));
        $tester = new CommandTester($this->command());

        $exitCode = $tester->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('mysql: access denied', $this->flat($tester));
    }

    #[Test]
    public function it_should_record_the_requester_given(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true, '--requester' => 'alice']);

        self::assertEquals(Requester::named('alice'), $this->jobs->latest()->requester);
    }

    #[Test]
    public function it_should_record_the_system_user_as_the_requester(): void
    {
        $user = getenv('USER');
        putenv('USER=bob');

        try {
            (new CommandTester($this->command()))->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true]);
        } finally {
            putenv(false === $user ? 'USER' : 'USER='.$user);
        }

        self::assertEquals(Requester::named('bob'), $this->jobs->latest()->requester);
    }

    #[Test]
    public function it_should_record_an_unknown_requester_without_a_name(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['dump' => 'dumps/prod.sql.gz', '--force' => true, '--requester' => ' ']);

        self::assertEquals(Requester::unknown(), $this->jobs->latest()->requester);
    }

    private function command(
        ?LoadJobRepository $jobs = null,
        ?Dumps $dumps = null,
        ?RestorePolicy $restorePolicy = null,
        ?LoadJobLauncher $launcher = null,
        ?AbortSignal $abortSignal = null,
        string $console = __FILE__,
    ): LoadDumpCommand {
        $jobs ??= $this->jobs;
        $dumpSource = $this->dumpSource($dumps ?? new Dumps(
            Dump::fromPath('dumps/prod.sql.gz', FileSize::fromBytes(1024), new \DateTimeImmutable('2026-09-18 07:00:00')),
            Dump::fromPath('dumps/staging.sql', FileSize::fromBytes(2048), new \DateTimeImmutable('2026-09-17 22:00:00')),
        ));
        $steps = new StepSequence([
            $this->restore,
            FakeOptionalStep::named('run_migrations', 200)->onByDefault(),
            FakeOptionalStep::named('clear_caches', 300),
        ]);
        $targetDatabaseDsn = new TargetDatabaseDsn('mysql://app:secret@127.0.0.1:3306/app');
        $abortSignal ??= $this->createStub(AbortSignal::class);

        $lock = $this->createStub(JobLock::class);
        $lock->method('isFree')->willReturn(false);
        $lock->method('acquire')->willReturn(true);

        $vanished = new VanishedJobs($lock, $this->clock, 60);

        return new LoadDumpCommand(
            new DashboardQuery($dumpSource, $steps, $jobs, $vanished, $targetDatabaseDsn, 20),
            $this->checkRequirements($dumpSource, $targetDatabaseDsn, $console),
            new RequestLoadJobHandler(
                dumps: $dumpSource,
                restorePolicy: $restorePolicy ?? $this->createStub(RestorePolicy::class),
                steps: $steps,
                jobs: $jobs,
                reconcileLostJobs: new ReconcileLostJobs($jobs, $vanished, $lock),
                launcher: $launcher ?? new SynchronousLauncher(new RunLoadJobHandler($jobs, $steps, $dumpSource, $lock, $abortSignal, $this->clock)),
                locks: new LockFactory(new InMemoryStore()),
                clock: $this->clock,
                retention: 10,
            ),
            new FollowLoadJobs($jobs, $vanished, new AbortLoadJobHandler($jobs, $abortSignal)),
            new Screens($this->clock, EnglishLabels::create()),
            EnglishLabels::create(),
        );
    }

    private function dumpSource(Dumps $dumps): DumpSource
    {
        $dumpSource = $this->createStub(DumpSource::class);
        $dumpSource->method('list')->willReturn($dumps);
        $dumpSource->method('open')->willReturn($this->createStub(SqlInput::class));
        $dumpSource->method('find')->willReturnCallback(static function (string $path) use ($dumps): Dump {
            foreach ($dumps as $dump) {
                if ($dump->path === $path) {
                    return $dump;
                }
            }

            throw DumpNotFound::atPath($path);
        });

        return $dumpSource;
    }

    private function checkRequirements(DumpSource $dumpSource, TargetDatabaseDsn $targetDatabaseDsn, string $console): CheckRequirements
    {
        $executableFinder = $this->createStub(ExecutableFinder::class);
        $executableFinder->method('find')->willReturnCallback(static fn (string $name): string => '/usr/bin/'.$name);

        return new CheckRequirements(
            dumpSource: $dumpSource,
            targetDatabaseDsn: $targetDatabaseDsn,
            varDir: sys_get_temp_dir(),
            console: $console,
            mysqlBinary: new MysqlBinary('mysql', $executableFinder),
            phpBinary: '/usr/bin/php',
            executableFinder: $executableFinder,
            disabledFunctions: '',
        );
    }

    private function aPendingJob(): LoadJobId
    {
        $job = LoadJob::request(
            id: LoadJobId::generate(),
            dump: Dump::fromPath('dumps/prod.sql.gz', FileSize::fromBytes(1024), new \DateTimeImmutable('2026-09-18 07:00:00')),
            options: LoadOptions::none(),
            steps: [],
            requester: Requester::unknown(),
            clock: $this->clock,
        );

        $this->jobs->save($job);

        return $job->id();
    }

    /**
     * @param \Closure(LoadJob): void $change
     */
    private function onLatestJob(\Closure $change): void
    {
        $job = $this->jobs->get($this->jobs->latest()->id);
        $change($job);
        $this->jobs->save($job);
    }

    private function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
