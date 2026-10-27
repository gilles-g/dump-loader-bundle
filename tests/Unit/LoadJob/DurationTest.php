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

use DumpLoader\LoadJob\Duration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DurationTest extends TestCase
{
    #[Test]
    public function it_should_count_the_seconds_between_two_instants(): void
    {
        $duration = Duration::between(new \DateTimeImmutable('2026-09-18 10:00:00'), new \DateTimeImmutable('2026-09-18 10:01:52'));

        self::assertSame(112, $duration->seconds);
    }

    #[Test]
    public function it_should_never_run_backwards_when_the_end_precedes_the_start(): void
    {
        $duration = Duration::between(new \DateTimeImmutable('2026-09-18 10:00:05'), new \DateTimeImmutable('2026-09-18 10:00:00'));

        self::assertSame(0, $duration->seconds);
    }

    #[Test]
    public function it_should_last_no_second_when_zero(): void
    {
        self::assertSame(0, Duration::zero()->seconds);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function humanizedDurations(): iterable
    {
        yield 'no second' => ['10:00:00', '0 s'];
        yield 'a few seconds' => ['10:00:04', '4 s'];
        yield 'just under a minute' => ['10:00:59', '59 s'];
        yield 'exactly a minute' => ['10:01:00', '1 min 00 s'];
        yield 'minutes and seconds' => ['10:01:52', '1 min 52 s'];
        yield 'just under an hour' => ['10:59:59', '59 min 59 s'];
        yield 'exactly an hour' => ['11:00:00', '1 h 00 min'];
        yield 'hours and minutes' => ['12:03:40', '2 h 03 min'];
    }

    #[Test]
    #[DataProvider('humanizedDurations')]
    public function it_should_read_like_a_stopwatch(string $end, string $expected): void
    {
        $duration = Duration::between(new \DateTimeImmutable('2026-09-18 10:00:00'), new \DateTimeImmutable('2026-09-18 '.$end));

        self::assertSame($expected, $duration->humanized());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function minutesAndSeconds(): iterable
    {
        yield 'no second' => ['10:00:00', '0:00'];
        yield 'a few seconds' => ['10:00:04', '0:04'];
        yield 'minutes and seconds' => ['10:01:52', '1:52'];
        yield 'beyond an hour, still in minutes' => ['11:15:03', '75:03'];
    }

    #[Test]
    #[DataProvider('minutesAndSeconds')]
    public function it_should_read_in_minutes_and_seconds(string $end, string $expected): void
    {
        $duration = Duration::between(new \DateTimeImmutable('2026-09-18 10:00:00'), new \DateTimeImmutable('2026-09-18 '.$end));

        self::assertSame($expected, $duration->minutesAndSeconds());
    }
}
