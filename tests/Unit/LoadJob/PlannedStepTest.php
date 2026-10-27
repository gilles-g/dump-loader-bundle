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

use DumpLoader\LoadJob\PlannedStep;
use DumpLoader\LoadJob\StepStatus;
use DumpLoader\Step\StepDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PlannedStepTest extends TestCase
{
    #[Test]
    public function it_should_freeze_a_definition_as_a_step_still_to_do(): void
    {
        $planned = PlannedStep::fromDefinition(new StepDefinition(
            name: 'restore_database',
            label: 'Restore database',
            description: 'Loads the dump into the target database.',
            rank: StepDefinition::RANK_RESTORE_DATABASE,
            weight: 10,
        ));

        self::assertSame('restore_database', $planned->name);
        self::assertSame('Restore database', $planned->label);
        self::assertSame(10, $planned->weight);
        self::assertSame(StepStatus::Todo, $planned->status);
    }

    #[Test]
    public function it_should_refuse_a_step_without_a_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PlannedStep(name: '', label: 'Restore database', weight: 10, status: StepStatus::Todo);
    }

    #[Test]
    public function it_should_refuse_a_weight_below_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PlannedStep(name: 'restore_database', label: 'Restore database', weight: 0, status: StepStatus::Todo);
    }

    #[Test]
    public function it_should_record_when_it_started_and_count_its_time_until_now_while_running(): void
    {
        $started = $this->aStepToDo()->start(new \DateTimeImmutable('2026-09-18 10:00:00'));

        self::assertSame(StepStatus::Running, $started->status);
        self::assertEquals(new \DateTimeImmutable('2026-09-18 10:00:00'), $started->startedAt);
        self::assertNull($started->finishedAt);
        self::assertSame(72, $started->elapsedAt(new \DateTimeImmutable('2026-09-18 10:01:12'))?->seconds);
    }

    #[Test]
    public function it_should_count_no_time_before_it_started(): void
    {
        self::assertNull($this->aStepToDo()->elapsedAt(new \DateTimeImmutable('2026-09-18 10:01:12')));
    }

    /**
     * @return iterable<string, array{\Closure(PlannedStep, \DateTimeImmutable): PlannedStep, StepStatus}>
     */
    public static function endings(): iterable
    {
        yield 'completed' => [static fn (PlannedStep $step, \DateTimeImmutable $at): PlannedStep => $step->complete($at), StepStatus::Done];
        yield 'failed' => [static fn (PlannedStep $step, \DateTimeImmutable $at): PlannedStep => $step->fail($at), StepStatus::Failed];
        yield 'aborted' => [static fn (PlannedStep $step, \DateTimeImmutable $at): PlannedStep => $step->abort($at), StepStatus::Aborted];
    }

    /**
     * @param \Closure(PlannedStep, \DateTimeImmutable): PlannedStep $end
     */
    #[Test]
    #[DataProvider('endings')]
    public function it_should_record_when_it_ended_and_how_long_it_lasted(\Closure $end, StepStatus $status): void
    {
        $started = $this->aStepToDo()->start(new \DateTimeImmutable('2026-09-18 10:00:00'));

        $ended = $end($started, new \DateTimeImmutable('2026-09-18 10:01:52'));

        self::assertSame($status, $ended->status);
        self::assertEquals(new \DateTimeImmutable('2026-09-18 10:00:00'), $ended->startedAt);
        self::assertEquals(new \DateTimeImmutable('2026-09-18 10:01:52'), $ended->finishedAt);
        self::assertSame(112, $ended->elapsedAt(new \DateTimeImmutable('2026-09-18 10:30:00'))?->seconds);
    }

    #[Test]
    public function it_should_keep_its_dates_through_its_array_form(): void
    {
        $step = $this->aStepToDo()
            ->start(new \DateTimeImmutable('2026-09-18 10:00:00'))
            ->complete(new \DateTimeImmutable('2026-09-18 10:01:52'));

        self::assertEquals($step, PlannedStep::fromArray($step->toArray()));
    }

    #[Test]
    public function it_should_have_no_date_when_read_from_an_array_written_before_steps_were_timed(): void
    {
        $step = PlannedStep::fromArray(['name' => 'restore_database', 'label' => 'Restore database', 'weight' => 10, 'status' => 'done', 'startedAt' => null, 'finishedAt' => null]);

        self::assertSame(StepStatus::Done, $step->status);
        self::assertNull($step->elapsedAt(new \DateTimeImmutable('2026-09-18 10:30:00')));
    }

    #[Test]
    public function it_should_refuse_an_unreadable_date(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PlannedStep::fromArray(['name' => 'restore_database', 'label' => 'Restore database', 'weight' => 10, 'status' => 'running', 'startedAt' => 'yesterday', 'finishedAt' => null]);
    }

    #[Test]
    public function it_should_know_which_statuses_leave_the_step_unfinished(): void
    {
        self::assertTrue(StepStatus::Todo->isUnfinished());
        self::assertTrue(StepStatus::Running->isUnfinished());
        self::assertFalse(StepStatus::Done->isUnfinished());
        self::assertFalse(StepStatus::Failed->isUnfinished());
        self::assertFalse(StepStatus::Aborted->isUnfinished());
    }

    private function aStepToDo(): PlannedStep
    {
        return new PlannedStep(name: 'restore_database', label: 'Restore database', weight: 10, status: StepStatus::Todo);
    }
}
