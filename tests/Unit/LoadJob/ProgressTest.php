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

namespace DumpLoader\Tests\Unit\LoadJob;

use DumpLoader\LoadJob\Percentage;
use DumpLoader\LoadJob\Progress;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProgressTest extends TestCase
{
    #[Test]
    public function it_should_start_with_nothing_started_yet(): void
    {
        $progress = Progress::notStarted(4);

        self::assertSame(0, $progress->stepNumber);
        self::assertSame(4, $progress->stepCount);
        self::assertSame(0.0, $progress->stepPercentage->value);
        self::assertSame(0.0, $progress->overall()->value);
    }

    #[Test]
    public function it_should_start_a_step_at_zero_percent(): void
    {
        $progress = Progress::atStep(1, 4);

        self::assertSame(1, $progress->stepNumber);
        self::assertSame(4, $progress->stepCount);
        self::assertSame(0.0, $progress->stepPercentage->value);
    }

    #[Test]
    public function it_should_count_the_steps_already_done_in_the_overall_percentage(): void
    {
        self::assertSame(0.0, Progress::atStep(1, 4)->overall()->value);
        self::assertSame(50.0, Progress::atStep(3, 4)->overall()->value);
    }

    #[Test]
    public function it_should_blend_the_current_step_percentage_in_the_overall_percentage(): void
    {
        $progress = Progress::atStep(2, 4)->withStepPercentage(Percentage::of(50));

        self::assertSame(37.5, $progress->overall()->value);
    }

    #[Test]
    public function it_should_be_one_hundred_percent_when_the_last_step_is_fully_done(): void
    {
        $progress = Progress::atStep(4, 4)->withStepPercentage(Percentage::complete());

        self::assertSame(100.0, $progress->overall()->value);
    }

    #[Test]
    public function it_should_replace_the_previous_step_percentage_with_the_latest_one(): void
    {
        $progress = Progress::atStep(1, 2)
            ->withStepPercentage(Percentage::of(80))
            ->withStepPercentage(Percentage::of(10));

        self::assertSame(10.0, $progress->stepPercentage->value);
    }

    #[Test]
    public function it_should_refuse_a_step_number_beyond_the_step_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Progress::atStep(5, 4);
    }

    #[Test]
    public function it_should_refuse_a_step_number_below_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Progress::atStep(0, 4);
    }

    #[Test]
    public function it_should_refuse_a_negative_step_count(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Progress::notStarted(-1);
    }
}
