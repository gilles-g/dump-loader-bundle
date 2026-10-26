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

namespace DumpLoader\Tests\Unit\Database;

use DumpLoader\Database\InvalidDsn;
use DumpLoader\Database\TargetDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TargetDatabaseTest extends TestCase
{
    #[Test]
    public function it_should_read_every_part_of_a_full_dsn(): void
    {
        $database = TargetDatabase::fromDsn('mysql://app:secret@db.internal:3307/app_preprod?charset=latin1&serverVersion=8.0.32');

        self::assertSame('db.internal', $database->host);
        self::assertSame(3307, $database->port);
        self::assertSame('app_preprod', $database->name);
        self::assertSame('app', $database->user);
        self::assertSame('secret', $database->password);
        self::assertSame('latin1', $database->charset);
    }

    #[DataProvider('supportedSchemes')]
    #[Test]
    public function it_should_accept_every_supported_mysql_scheme(string $scheme): void
    {
        $database = TargetDatabase::fromDsn($scheme.'://app:secret@127.0.0.1:3306/app');

        self::assertSame('app', $database->name);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function supportedSchemes(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'mysqli' => ['mysqli'];
        yield 'pdo-mysql' => ['pdo-mysql'];
        yield 'mariadb' => ['mariadb'];
    }

    #[Test]
    public function it_should_fall_back_on_the_default_port_and_charset(): void
    {
        $database = TargetDatabase::fromDsn('mysql://app:secret@127.0.0.1/app');

        self::assertSame(3306, $database->port);
        self::assertSame('utf8mb4', $database->charset);
    }

    #[Test]
    public function it_should_accept_an_empty_password(): void
    {
        $database = TargetDatabase::fromDsn('mysql://root@127.0.0.1/app');

        self::assertSame('root', $database->user);
        self::assertSame('', $database->password);
    }

    #[Test]
    public function it_should_urldecode_user_and_password(): void
    {
        $database = TargetDatabase::fromDsn('mysql://us%40er:p%40ss%3Aword%2F1@127.0.0.1/app');

        self::assertSame('us@er', $database->user);
        self::assertSame('p@ss:word/1', $database->password);
    }

    #[Test]
    public function it_should_reject_a_unix_socket_dsn_because_the_client_is_driven_by_host_and_port(): void
    {
        $this->expectException(InvalidDsn::class);

        TargetDatabase::fromDsn('mysql://app:secret@localhost/app?unix_socket=/var/run/mysqld/mysqld.sock');
    }

    #[DataProvider('rejectedDsn')]
    #[Test]
    public function it_should_reject_a_dsn_it_cannot_target(string $dsn): void
    {
        $this->expectException(InvalidDsn::class);

        TargetDatabase::fromDsn($dsn);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedDsn(): iterable
    {
        yield 'unsupported driver' => ['pgsql://app:secret@127.0.0.1:5432/app'];
        yield 'no scheme' => ['127.0.0.1/app'];
        yield 'no database name' => ['mysql://app:secret@127.0.0.1:3306'];
        yield 'empty database name' => ['mysql://app:secret@127.0.0.1:3306/'];
        yield 'no host' => ['mysql:///app'];
        yield 'host omitted but path readable' => ['mysql:/app'];
        yield 'empty' => [''];
    }

    #[Test]
    public function it_should_never_expose_the_password_in_its_displayable_identity(): void
    {
        $database = TargetDatabase::fromDsn('mysql://app:secret@db.internal:3307/app_preprod');

        self::assertSame('db.internal:3307/app_preprod', $database->displayName());
    }
}
