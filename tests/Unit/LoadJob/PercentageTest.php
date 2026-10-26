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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PercentageTest extends TestCase
{
    #[Test]
    public function it_should_have_zero_and_complete_as_bounds(): void
    {
        self::assertSame(0.0, Percentage::zero()->value);
        self::assertSame(100.0, Percentage::complete()->value);
    }

    #[Test]
    public function it_should_accept_a_value_inside_the_bounds(): void
    {
        self::assertSame(42.5, Percentage::of(42.5)->value);
    }

    #[Test]
    public function it_should_refuse_a_value_below_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Percentage::of(-0.1);
    }

    #[Test]
    public function it_should_refuse_a_value_above_one_hundred(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Percentage::of(100.1);
    }

    #[Test]
    public function it_should_round_for_display(): void
    {
        self::assertSame(43, Percentage::of(42.6)->toInt());
    }

    #[Test]
    public function it_should_compare_by_value(): void
    {
        self::assertTrue(Percentage::of(50)->equals(Percentage::of(50.0)));
        self::assertFalse(Percentage::of(50)->equals(Percentage::of(51)));
    }
}
