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

namespace DumpLoader\Tests\Unit\Step;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\FileSize;
use DumpLoader\Execution\CommandFailed;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Step\LoadContext;
use DumpLoader\Step\OptionalStep;
use DumpLoader\Step\RunMigrations;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepFailed;
use DumpLoader\Step\StepTracker;
use DumpLoader\Tests\Double\RecordingProcessRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RunMigrationsTest extends TestCase
{
    #[Test]
    public function it_should_be_an_optional_step_checked_by_default(): void
    {
        $step = new RunMigrations(RecordingProcessRunner::succeeding(), '/usr/bin/php', '/app/bin/console');

        $definition = $step->definition();

        self::assertInstanceOf(OptionalStep::class, $step);
        self::assertTrue($step->enabledByDefault());
        self::assertSame('run_migrations', $definition->name);
        self::assertSame(StepDefinition::RANK_RUN_MIGRATIONS, $definition->rank);
    }

    #[Test]
    public function it_should_run_the_doctrine_migrations_without_interaction(): void
    {
        $runner = RecordingProcessRunner::succeeding();

        $step = new RunMigrations($runner, '/usr/bin/php', '/app/bin/console');
        $step->run(self::context(), $this->createStub(StepTracker::class));

        self::assertCount(1, $runner->commands);
        self::assertSame(
            ['/usr/bin/php', '/app/bin/console', 'doctrine:migrations:migrate', '-n'],
            $runner->lastCommand()->argv,
        );
    }

    #[Test]
    public function it_should_fail_the_step_when_the_console_command_exits_with_an_error(): void
    {
        $runner = RecordingProcessRunner::failingWith(1, ['Migration DoctrineMigrations\\Version1 failed.']);

        $step = new RunMigrations($runner, '/usr/bin/php', '/app/bin/console');

        try {
            $step->run(self::context(), $this->createStub(StepTracker::class));
            self::fail('Expected the step to fail.');
        } catch (StepFailed $failure) {
            self::assertInstanceOf(CommandFailed::class, $failure->getPrevious());
        }
    }

    private static function context(): LoadContext
    {
        return new LoadContext(
            LoadJobId::generate(),
            Dump::fromPath('2026-09-18.sql', FileSize::fromBytes(10), new \DateTimeImmutable('2026-09-18 10:00:00')),
            LoadOptions::fromNames(['run_migrations']),
        );
    }
}
