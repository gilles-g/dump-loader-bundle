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

use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Step\OptionalStep;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepSequence;
use DumpLoader\Step\UnknownStep;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StepSequenceTest extends TestCase
{
    #[Test]
    public function it_should_sort_mandatory_steps_by_rank_when_planning(): void
    {
        $caches = $this->step('clear_caches', 300);
        $restore = $this->step('restore_database', 100);
        $migrations = $this->step('run_migrations', 200);

        $sequence = new StepSequence([$caches, $restore, $migrations]);

        self::assertSame([$restore, $migrations, $caches], $sequence->plan(LoadOptions::none()));
    }

    #[Test]
    public function it_should_keep_only_checked_optional_steps_when_planning(): void
    {
        $restore = $this->step('restore_database', 100);
        $migrations = $this->optionalStep('run_migrations', 200);
        $caches = $this->optionalStep('clear_caches', 300);

        $sequence = new StepSequence([$restore, $migrations, $caches]);

        self::assertSame([$restore, $caches], $sequence->plan(LoadOptions::fromNames(['clear_caches'])));
    }

    #[Test]
    public function it_should_ignore_option_names_matching_no_step_when_planning(): void
    {
        $restore = $this->step('restore_database', 100);

        $sequence = new StepSequence([$restore]);

        self::assertSame([$restore], $sequence->plan(LoadOptions::fromNames(['anonymize'])));
    }

    #[Test]
    public function it_should_never_drop_a_mandatory_step_even_when_its_name_is_not_checked(): void
    {
        $restore = $this->step('restore_database', 100);

        $sequence = new StepSequence([$restore]);

        self::assertSame([$restore], $sequence->plan(LoadOptions::none()));
    }

    #[Test]
    public function it_should_list_available_options_sorted_by_rank(): void
    {
        $restore = $this->step('restore_database', 100);
        $caches = $this->optionalStep('clear_caches', 300);
        $migrations = $this->optionalStep('run_migrations', 200);

        $sequence = new StepSequence([$restore, $caches, $migrations]);

        self::assertSame([$migrations, $caches], $sequence->availableOptions());
    }

    #[Test]
    public function it_should_have_no_available_option_without_optional_step(): void
    {
        $sequence = new StepSequence([$this->step('restore_database', 100)]);

        self::assertSame([], $sequence->availableOptions());
    }

    #[Test]
    public function it_should_return_the_registered_step_by_name(): void
    {
        $restore = $this->step('restore_database', 100);
        $sequence = new StepSequence([$this->optionalStep('clear_caches', 300), $restore]);

        self::assertSame($restore, $sequence->named('restore_database'));
    }

    #[Test]
    public function it_should_refuse_a_step_by_name_that_is_no_longer_registered(): void
    {
        $sequence = new StepSequence([$this->step('restore_database', 100)]);

        $this->expectException(UnknownStep::class);
        $this->expectExceptionMessage('anonymize_data');

        $sequence->named('anonymize_data');
    }

    private function step(string $name, int $rank): Step
    {
        $step = $this->createMock(Step::class);
        $step->method('definition')->willReturn($this->definition($name, $rank));

        return $step;
    }

    private function optionalStep(string $name, int $rank): OptionalStep
    {
        $step = $this->createMock(OptionalStep::class);
        $step->method('definition')->willReturn($this->definition($name, $rank));
        $step->method('enabledByDefault')->willReturn(false);

        return $step;
    }

    private function definition(string $name, int $rank): StepDefinition
    {
        return new StepDefinition(
            name: $name,
            label: $name,
            description: '',
            rank: $rank,
            weight: 1,
        );
    }
}
