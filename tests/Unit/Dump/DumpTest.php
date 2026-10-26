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
use DumpLoader\Dump\DumpFormat;
use DumpLoader\Dump\FileSize;
use DumpLoader\Dump\UnsupportedDumpFormat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DumpTest extends TestCase
{
    #[Test]
    public function it_should_describe_a_gzipped_dump_file(): void
    {
        $modifiedAt = new \DateTimeImmutable('2026-01-05 10:00:00');

        $dump = Dump::fromPath('backups/2026-01-05.preprod.sql.gz', FileSize::fromBytes(2048), $modifiedAt);

        self::assertSame('backups/2026-01-05.preprod.sql.gz', $dump->path);
        self::assertSame('2026-01-05.preprod.sql.gz', $dump->name);
        self::assertSame(2048, $dump->size->bytes);
        self::assertSame($modifiedAt, $dump->modifiedAt);
        self::assertSame(DumpFormat::GzippedSql, $dump->format);
    }

    #[Test]
    public function it_should_keep_the_whole_name_when_the_path_has_no_directory(): void
    {
        $dump = Dump::fromPath('shop.sql', FileSize::zero(), new \DateTimeImmutable('2026-01-05 10:00:00'));

        self::assertSame('shop.sql', $dump->name);
        self::assertSame(DumpFormat::Sql, $dump->format);
    }

    #[Test]
    public function it_should_refuse_a_file_that_is_not_a_dump(): void
    {
        $this->expectException(UnsupportedDumpFormat::class);

        Dump::fromPath('backups/notes.txt', FileSize::zero(), new \DateTimeImmutable('2026-01-05 10:00:00'));
    }
}
