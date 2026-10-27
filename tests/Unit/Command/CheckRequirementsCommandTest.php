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

namespace DumpLoader\Tests\Unit\Command;

use DumpLoader\Command\CheckRequirementsCommand;
use DumpLoader\Database\Mysql\MysqlBinary;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\Dumps;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Requirements\CheckRequirements;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\ExecutableFinder;

final class CheckRequirementsCommandTest extends TestCase
{
    #[Test]
    public function it_should_succeed_and_list_every_requirement_when_the_host_is_ready(): void
    {
        $tester = new CommandTester(new CheckRequirementsCommand($this->check()));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);

        $display = $tester->getDisplay();

        self::assertStringContainsString('Requirement', $display);
        self::assertStringContainsString('requirements.mysql_binary', $display);
        self::assertStringContainsString('Every requirement is met.', $display);
    }

    #[Test]
    public function it_should_fail_and_name_the_unmet_requirements(): void
    {
        $executableFinder = $this->createStub(ExecutableFinder::class);
        $executableFinder->method('find')->willReturn(null);

        $tester = new CommandTester(new CheckRequirementsCommand($this->check($executableFinder)));
        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('requirements.mysql_binary', $tester->getDisplay());
    }

    private function check(?ExecutableFinder $executableFinder = null): CheckRequirements
    {
        $dumpSource = $this->createStub(DumpSource::class);
        $dumpSource->method('list')->willReturn(new Dumps());

        if (null === $executableFinder) {
            $executableFinder = $this->createStub(ExecutableFinder::class);
            $executableFinder->method('find')->willReturnCallback(static fn (string $name): string => '/usr/bin/'.$name);
        }

        return new CheckRequirements(
            dumpSource: $dumpSource,
            targetDatabaseDsn: new TargetDatabaseDsn('mysql://app:secret@127.0.0.1:3306/app'),
            varDir: sys_get_temp_dir(),
            console: __FILE__,
            mysqlBinary: new MysqlBinary('mysql', $executableFinder),
            phpBinary: '/usr/bin/php',
            executableFinder: $executableFinder,
        );
    }
}
