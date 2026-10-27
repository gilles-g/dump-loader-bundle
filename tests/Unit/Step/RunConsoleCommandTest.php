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

namespace DumpLoader\Tests\Unit\Step;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\FileSize;
use DumpLoader\Execution\CommandFailed;
use DumpLoader\Execution\ShellCommand;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Step\LoadContext;
use DumpLoader\Step\OptionalStep;
use DumpLoader\Step\RunConsoleCommand;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepFailed;
use DumpLoader\Step\StepTracker;
use DumpLoader\Tests\Double\RecordingProcessRunner;
use DumpLoader\Tests\Double\RecordingStepTracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunConsoleCommandTest extends TestCase
{
    #[Test]
    public function it_should_be_an_optional_step_carrying_its_configured_definition(): void
    {
        $step = self::step(RecordingProcessRunner::succeeding(), enabledByDefault: false);

        $definition = $step->definition();

        self::assertInstanceOf(OptionalStep::class, $step);
        self::assertFalse($step->enabledByDefault());
        self::assertSame('reset_admin_password', $definition->name);
        self::assertSame('Reset the admin password', $definition->label);
        self::assertSame(StepDefinition::RANK_AFTER_LOAD, $definition->rank);
        self::assertGreaterThan(StepDefinition::RANK_CLEAR_CACHES, $definition->rank);
    }

    #[Test]
    public function it_should_run_the_configured_console_command(): void
    {
        $runner = RecordingProcessRunner::succeeding();

        self::step($runner)->run(self::context(), $this->createStub(StepTracker::class));

        self::assertCount(1, $runner->commands);
        self::assertSame(
            ['/usr/bin/php', '/app/bin/console', 'dbal:run-sql', 'UPDATE `admin` SET `password` = "hash";'],
            $runner->lastCommand()->argv,
        );
    }

    #[Test]
    public function it_should_keep_the_command_arguments_out_of_the_log(): void
    {
        $tracker = RecordingStepTracker::silent();

        self::step(RecordingProcessRunner::succeeding())->run(self::context(), $tracker);

        self::assertSame(
            ['Reset the admin password', 'The "reset_admin_password" command has been run.'],
            $tracker->messages,
        );
    }

    #[Test]
    public function it_should_fail_the_step_when_the_console_command_exits_with_an_error(): void
    {
        $runner = RecordingProcessRunner::failingWith(1, ['SQLSTATE[42S02]: Base table or view not found']);

        try {
            self::step($runner)->run(self::context(), $this->createStub(StepTracker::class));
            self::fail('Expected the step to fail.');
        } catch (StepFailed $failure) {
            self::assertSame('The "reset_admin_password" command could not be run.', $failure->getMessage());
            self::assertInstanceOf(CommandFailed::class, $failure->getPrevious());
        }
    }

    private static function step(RecordingProcessRunner $runner, bool $enabledByDefault = true): RunConsoleCommand
    {
        return new RunConsoleCommand(
            $runner,
            new StepDefinition(
                name: 'reset_admin_password',
                label: 'Reset the admin password',
                description: '',
                rank: StepDefinition::RANK_AFTER_LOAD,
                weight: 1,
            ),
            new ShellCommand([
                '/usr/bin/php',
                '/app/bin/console',
                'dbal:run-sql',
                'UPDATE `admin` SET `password` = "hash";',
            ]),
            $enabledByDefault,
        );
    }

    private static function context(): LoadContext
    {
        return new LoadContext(
            LoadJobId::generate(),
            Dump::fromPath('2026-09-18.sql', FileSize::fromBytes(10), new \DateTimeImmutable('2026-09-18 10:00:00')),
            LoadOptions::fromNames(['reset_admin_password']),
        );
    }
}
