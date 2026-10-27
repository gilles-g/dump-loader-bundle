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

use DumpLoader\Database\Mysql\MysqlBinary;
use DumpLoader\Database\Mysql\MysqlBinaryNotFound;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;

final class MysqlBinaryTest extends TestCase
{
    #[Test]
    public function it_should_find_the_configured_client_in_the_path(): void
    {
        self::assertSame('/usr/bin/mysql', (new MysqlBinary('mysql', $this->finderFinding('mysql', 'mariadb')))->locate());
    }

    #[Test]
    public function it_should_fall_back_to_the_mariadb_client(): void
    {
        self::assertSame('/usr/bin/mariadb', (new MysqlBinary('mysql', $this->finderFinding('mariadb')))->locate());
    }

    #[Test]
    public function it_should_fail_when_neither_the_client_nor_its_fallback_is_in_the_path(): void
    {
        $this->expectException(MysqlBinaryNotFound::class);
        $this->expectExceptionMessage('Neither "mysql" nor "mariadb"');

        (new MysqlBinary('mysql', $this->finderFinding()))->locate();
    }

    #[Test]
    public function it_should_use_an_absolute_path_without_searching_the_path(): void
    {
        $executableFinder = $this->createMock(ExecutableFinder::class);
        $executableFinder->expects(self::never())->method('find');

        self::assertSame('/bin/sh', (new MysqlBinary('/bin/sh', $executableFinder))->locate());
    }

    #[Test]
    public function it_should_refuse_an_absolute_path_that_is_not_executable(): void
    {
        $this->expectException(MysqlBinaryNotFound::class);
        $this->expectExceptionMessage('"/nowhere/mysql" is not an executable file');

        (new MysqlBinary('/nowhere/mysql', $this->finderFinding('mysql', 'mariadb')))->locate();
    }

    private function finderFinding(string ...$names): ExecutableFinder
    {
        $executableFinder = $this->createStub(ExecutableFinder::class);
        $executableFinder->method('find')->willReturnCallback(
            static fn (string $name): ?string => \in_array($name, $names, true) ? '/usr/bin/'.$name : null,
        );

        return $executableFinder;
    }
}
