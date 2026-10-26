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

use DumpLoader\LoadJob\LogLine;
use DumpLoader\LoadJob\LogTag;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LogLineTest extends TestCase
{
    #[Test]
    public function it_should_survive_a_round_trip_through_its_array_form(): void
    {
        $line = new LogLine(
            at: new \DateTimeImmutable('2026-09-18 08:00:00'),
            tag: LogTag::Run,
            message: 'Restoring the database.',
        );

        self::assertEquals($line, LogLine::fromArray($line->toArray()));
    }

    #[Test]
    public function it_should_refuse_a_date_it_cannot_read(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LogLine::fromArray(['at' => 'yesterday', 'tag' => 'run', 'message' => 'Restoring the database.']);
    }
}
