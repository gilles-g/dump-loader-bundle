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

namespace DumpLoader\Tests\Unit\Dump;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\Dumps;
use DumpLoader\Dump\FileSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DumpsTest extends TestCase
{
    #[Test]
    public function it_should_be_empty_when_no_dump_is_available(): void
    {
        $dumps = new Dumps();

        self::assertTrue($dumps->isEmpty());
        self::assertCount(0, $dumps);
        self::assertSame([], iterator_to_array($dumps, false));
    }

    #[Test]
    public function it_should_iterate_in_the_given_order(): void
    {
        $latest = self::dump('latest.sql');
        $oldest = self::dump('oldest.sql');

        $dumps = new Dumps($latest, $oldest);

        self::assertFalse($dumps->isEmpty());
        self::assertCount(2, $dumps);
        self::assertSame([$latest, $oldest], iterator_to_array($dumps, false));
    }

    private static function dump(string $name): Dump
    {
        return Dump::fromPath($name, FileSize::fromBytes(10), new \DateTimeImmutable('2026-01-05 10:00:00'));
    }
}
