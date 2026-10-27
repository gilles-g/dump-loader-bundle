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

namespace DumpLoader\Tests\Unit\Database\Mysql;

use DumpLoader\Database\Mysql\DumpTargetsExplicitDatabase;
use DumpLoader\Database\Mysql\MysqlRestorePolicy;
use DumpLoader\Dump\DumpNotRestorable;
use DumpLoader\Dump\PlainSqlStream;
use DumpLoader\Dump\StreamedSqlInput;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MysqlRestorePolicyTest extends TestCase
{
    #[DataProvider('headersTargetingAnExplicitDatabase')]
    #[Test]
    public function it_should_refuse_a_dump_targeting_an_explicit_database(string $header, string $statement): void
    {
        try {
            new MysqlRestorePolicy()->ensureRestorable(self::input($header));
            self::fail('The dump should have been refused.');
        } catch (DumpTargetsExplicitDatabase $refusal) {
            self::assertInstanceOf(DumpNotRestorable::class, $refusal);
            self::assertStringContainsString(\sprintf('"%s"', $statement), $refusal->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function headersTargetingAnExplicitDatabase(): iterable
    {
        yield 'create database' => ["-- MySQL dump\nCREATE DATABASE /*!32312 IF NOT EXISTS*/ `other`;\n", 'CREATE DATABASE'];
        yield 'use' => ["-- MySQL dump\nUSE `other`;\nINSERT INTO t VALUES (1);", 'USE'];
        yield 'lowercase create database' => ["create database `other`;\n", 'create database'];
    }

    #[Test]
    public function it_should_accept_a_dump_mentioning_the_database_in_its_comments_only_and_leave_it_whole(): void
    {
        $header = "-- MySQL dump 10.13\n-- Host: db    Database: shop\n-- The CREATE DATABASE statement is not emitted here.\nDROP TABLE IF EXISTS `t`;\n";
        $input = self::input($header);

        new MysqlRestorePolicy()->ensureRestorable($input);

        self::assertSame($header, implode('', iterator_to_array($input, false)));
    }

    #[DoesNotPerformAssertions]
    #[Test]
    public function it_should_only_read_the_header_of_the_dump(): void
    {
        $dump = 'DROP TABLE IF EXISTS `t`;'.str_repeat("-- padding\n", 1000)."USE `other`;\n";

        new MysqlRestorePolicy()->ensureRestorable(self::input($dump));
    }

    private static function input(string $sql): StreamedSqlInput
    {
        $source = fopen('php://memory', 'r+');
        \assert(\is_resource($source));
        fwrite($source, $sql);
        rewind($source);

        return new StreamedSqlInput(PlainSqlStream::over($source, 16));
    }
}
