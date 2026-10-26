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

namespace DumpLoader\Tests\Unit\Command\Screen;

use DumpLoader\Command\LoadRefused;
use DumpLoader\Command\Screen\TuiScreen;
use DumpLoader\Dump\Dump;
use DumpLoader\Dump\Dumps;
use DumpLoader\Dump\FileSize;
use DumpLoader\LoadJob\AbortLoadJobHandler;
use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\FollowedLoadJob;
use DumpLoader\LoadJob\FollowLoadJobs;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\VanishedJobs;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Tests\Double\EnglishLabels;
use DumpLoader\Tests\Double\InMemoryLoadJobRepository;
use DumpLoader\Tests\Unit\LoadJob\JobFixtures;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Revolt\EventLoop;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class TuiScreenTest extends TestCase
{
    use JobFixtures;

    private const ENTER = "\r";
    private const DOWN = "\x1b[B";
    private const ESCAPE = "\x1b";
    private const CTRL_C = "\x03";
    private const KITTY_SPACE = "\x1b[32u";

    private VirtualTerminal $terminal;

    private MockClock $clock;

    protected function setUp(): void
    {
        if (!class_exists(Tui::class)) {
            self::markTestSkipped('symfony/tui is not installed.');
        }

        $this->terminal = new VirtualTerminal(100, 30);
        $this->clock = new MockClock('2026-09-18 08:01:42');
    }

    #[Test]
    public function it_should_choose_the_dump_picked_in_the_list(): void
    {
        $this->type(self::DOWN, self::ENTER);

        self::assertSame('dumps/staging.sql', (new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->chooseDump($this->twoDumps()));
        self::assertStringContainsString('staging.sql', $this->terminal->getOutput());
        self::assertStringContainsString('2.0 KB, 2026-09-17 22:00', $this->terminal->getOutput());
    }

    #[Test]
    public function it_should_refuse_to_go_on_when_the_dump_choice_is_cancelled(): void
    {
        $this->type(self::ESCAPE);

        $this->expectExceptionObject(LoadRefused::cancelled());

        (new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->chooseDump($this->twoDumps());
    }

    #[Test]
    public function it_should_toggle_the_options_from_their_defaults(): void
    {
        $this->type(' ', self::DOWN, ' ', self::ENTER);

        $options = (new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->chooseOptions(
            [$this->anOption('run_migrations', 'Plays the pending migrations'), $this->anOption('clear_caches')],
            LoadOptions::fromNames(['run_migrations']),
        );

        self::assertSame(['clear_caches'], $options->names);
        self::assertStringContainsString('Plays the pending migrations', $this->terminal->getOutput());
    }

    #[Test]
    public function it_should_toggle_the_options_with_the_space_of_the_kitty_keyboard_protocol(): void
    {
        $this->terminal->setKittyProtocolActive(true);
        $this->type(self::KITTY_SPACE, self::DOWN, self::KITTY_SPACE, self::ENTER);

        $options = (new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->chooseOptions(
            [$this->anOption('run_migrations'), $this->anOption('clear_caches')],
            LoadOptions::fromNames(['run_migrations']),
        );

        self::assertSame(['clear_caches'], $options->names);
    }

    #[Test]
    public function it_should_show_each_option_as_a_checkbox_before_its_label(): void
    {
        $this->terminal = new VirtualTerminal(200, 30);
        $this->type(' ', self::ENTER);

        (new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->chooseOptions(
            [$this->anOption('run_migrations'), $this->anOption('clear_caches')],
            LoadOptions::fromNames(['clear_caches']),
        );

        $screen = $this->screen();

        self::assertMatchesRegularExpression('/^→ \[ \] Run_migrations\s*$/m', $screen);
        self::assertMatchesRegularExpression('/^  \[x\] Clear_caches\s*$/m', $screen);
    }

    #[Test]
    public function it_should_skip_the_options_question_when_there_is_no_option(): void
    {
        self::assertSame([], (new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->chooseOptions([], LoadOptions::none())->names);
        self::assertSame('', $this->terminal->getOutput());
    }

    #[Test]
    public function it_should_refuse_to_go_on_when_the_options_are_cancelled(): void
    {
        $this->type(self::ESCAPE);

        $this->expectExceptionObject(LoadRefused::cancelled());

        (new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->chooseOptions([$this->anOption('run_migrations')], LoadOptions::none());
    }

    #[Test]
    public function it_should_keep_the_database_unless_the_drop_is_confirmed(): void
    {
        $this->type(self::ENTER);

        self::assertFalse((new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->confirm('db.internal:3306/app'));
        self::assertStringContainsString('Every table of "db.internal:3306/app" will be dropped', $this->terminal->getOutput());
    }

    #[Test]
    public function it_should_confirm_the_drop_when_asked_to(): void
    {
        $this->type(self::DOWN, self::ENTER);

        self::assertTrue((new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->confirm('db.internal:3306/app'));
    }

    #[Test]
    public function it_should_follow_the_job_and_abort_it_on_ctrl_c(): void
    {
        $jobs = new InMemoryLoadJobRepository();
        $job = $this->aRunningJob($jobs);

        $abortSignal = $this->createMock(AbortSignal::class);
        $abortSignal->expects(self::once())->method('request')->with($job->id());

        $followed = $this->follow($jobs, $job, $abortSignal);

        $this->type(self::CTRL_C);
        EventLoop::delay(0.3, static function () use ($jobs, $job): void {
            $job->abort();
            $jobs->save($job);
        });

        (new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->follow($followed);

        self::assertTrue($followed->isOver());

        $output = $this->screen();
        self::assertStringContainsString('Loading "prod-2026-09-18.sql.gz"', $output);
        self::assertStringContainsString('Restore database — 1:42', $output);
        self::assertStringContainsString('Aborting… — 1:42', $output);
        self::assertStringContainsString('✘ Aborted — 0:00', $output);
        self::assertStringContainsString('stop  Load job aborted on request.', $output);
    }

    #[Test]
    public function it_should_say_it_waits_while_the_job_has_not_started(): void
    {
        $jobs = new InMemoryLoadJobRepository();
        $job = LoadJob::request(
            id: $this->aJobId(),
            dump: $this->aDump(),
            options: LoadOptions::none(),
            steps: $this->plannedSteps(),
            requester: Requester::unknown(),
            clock: new MockClock('2026-09-18 08:00:00'),
        );
        $jobs->save($job);
        $followed = $this->follow($jobs, $job, $this->createStub(AbortSignal::class));

        EventLoop::delay(0.3, static function () use ($jobs, $job): void {
            $job->fail('The launcher could not start the job.');
            $jobs->save($job);
        });

        (new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->follow($followed);

        self::assertStringContainsString('Waiting for the load job to start… — 0:00', $this->screen());
        self::assertStringContainsString('✘ Failed — 0:00', $this->screen());
    }

    #[Test]
    public function it_should_keep_only_the_last_log_lines_on_screen(): void
    {
        $jobs = new InMemoryLoadJobRepository();
        $job = $this->aRunningJob($jobs);

        foreach (range(1, 15) as $number) {
            $job->log(LogTag::Run, \sprintf('line %02d', $number));
        }

        $jobs->save($job);
        $followed = $this->follow($jobs, $job, $this->createStub(AbortSignal::class));

        EventLoop::delay(0.3, static function () use ($jobs, $job): void {
            $job->log(LogTag::Run, 'line 16');
            $job->completeStep();
            $job->complete();
            $jobs->save($job);
        });

        (new TuiScreen(EnglishLabels::create(), $this->clock, $this->terminal))->follow($followed);

        self::assertStringContainsString('done  Load job completed.', $this->screen());
        self::assertStringContainsString('✔ Completed — 0:00', $this->screen());
    }

    private function screen(): string
    {
        return (string) preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $this->terminal->getOutput());
    }

    private function type(string ...$keys): void
    {
        EventLoop::delay(0.05, function () use ($keys): void {
            foreach ($keys as $key) {
                $this->terminal->simulateInput($key);
            }
        });
    }

    private function twoDumps(): Dumps
    {
        return new Dumps(
            Dump::fromPath('dumps/prod.sql.gz', FileSize::fromBytes(1024), new \DateTimeImmutable('2026-09-18 07:00:00')),
            Dump::fromPath('dumps/staging.sql', FileSize::fromBytes(2048), new \DateTimeImmutable('2026-09-17 22:00:00')),
        );
    }

    private function anOption(string $name, string $description = ''): StepDefinition
    {
        return new StepDefinition($name, ucfirst($name), $description, 200, 1);
    }

    private function aRunningJob(InMemoryLoadJobRepository $jobs): LoadJob
    {
        $job = LoadJob::request(
            id: $this->aJobId(),
            dump: $this->aDump(),
            options: LoadOptions::none(),
            steps: $this->plannedSteps(),
            requester: Requester::unknown(),
            clock: new MockClock('2026-09-18 08:00:00'),
        );
        $job->start();
        $job->startStep('restore_database');
        $jobs->save($job);

        return $job;
    }

    private function follow(InMemoryLoadJobRepository $jobs, LoadJob $job, AbortSignal $abortSignal): FollowedLoadJob
    {
        $lock = $this->createStub(JobLock::class);
        $lock->method('isFree')->willReturn(false);

        return (new FollowLoadJobs(
            $jobs,
            new VanishedJobs($lock, new MockClock('2026-09-18 08:00:00'), 60),
            new AbortLoadJobHandler($jobs, $abortSignal),
        ))->follow($job->id());
    }
}
