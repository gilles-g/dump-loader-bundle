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

namespace DumpLoader\Tests\Unit\Requirements;

use DumpLoader\Database\Mysql\MysqlBinary;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\Dump;
use DumpLoader\Dump\Dumps;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\FileSize;
use DumpLoader\Requirements\CheckRequirements;
use DumpLoader\Requirements\Requirement;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\PhpExecutableFinder;

final class CheckRequirementsTest extends TestCase
{
    #[Test]
    public function it_should_report_every_requirement_as_met_on_a_sane_host(): void
    {
        $requirements = ($this->check())();

        self::assertCount(8, $requirements);
        self::assertTrue($requirements->allSatisfied());
        self::assertSame([], $requirements->unsatisfied());
    }

    #[Test]
    public function it_should_report_a_missing_mysql_binary(): void
    {
        $requirements = ($this->check(mysqlBinary: new MysqlBinary('mysql', $this->finderWithout('mysql', 'mariadb'))))();

        self::assertFalse($requirements->allSatisfied());
        self::assertSame('requirements.mysql_binary', $this->labelsOf($requirements->unsatisfied())[0]);
        self::assertStringContainsString('Neither "mysql" nor "mariadb"', $this->detailOf($requirements, 'requirements.mysql_binary'));
    }

    #[Test]
    public function it_should_accept_a_host_shipping_only_the_mariadb_client(): void
    {
        $requirements = ($this->check(mysqlBinary: new MysqlBinary('mysql', $this->finderWithout('mysql'))))();

        self::assertTrue($requirements->allSatisfied());
        self::assertSame('/usr/bin/mariadb', $this->detailOf($requirements, 'requirements.mysql_binary'));
    }

    #[Test]
    public function it_should_accept_a_mysql_binary_configured_by_its_absolute_path(): void
    {
        $requirements = ($this->check(mysqlBinary: new MysqlBinary('/bin/sh', $this->finderWithout('sh'))))();

        self::assertTrue($requirements->allSatisfied());
        self::assertSame('/bin/sh', $this->detailOf($requirements, 'requirements.mysql_binary'));
    }

    #[Test]
    public function it_should_report_a_missing_php_binary(): void
    {
        $phpExecutableFinder = $this->createStub(PhpExecutableFinder::class);
        $phpExecutableFinder->method('find')->willReturn(false);

        $requirements = ($this->check(phpBinary: null, phpExecutableFinder: $phpExecutableFinder))();

        self::assertContains('requirements.php_binary', $this->labelsOf($requirements->unsatisfied()));
    }

    #[Test]
    public function it_should_accept_a_host_without_setsid(): void
    {
        $requirements = ($this->check(executableFinder: $this->finderWithout('setsid')))();

        self::assertTrue($requirements->allSatisfied());
        self::assertStringContainsString('nohup', $this->detailOf($requirements, 'requirements.setsid'));
    }

    #[Test]
    public function it_should_report_a_var_dir_that_cannot_be_created(): void
    {
        $requirements = ($this->check(varDir: '/proc/nope/dump-loader'))();

        self::assertContains('requirements.var_dir', $this->labelsOf($requirements->unsatisfied()));
    }

    #[Test]
    public function it_should_accept_a_var_dir_that_does_not_exist_yet(): void
    {
        $requirements = ($this->check(varDir: sys_get_temp_dir().'/dump-loader-requirements-'.uniqid()))();

        self::assertTrue($requirements->allSatisfied());
        self::assertStringContainsString('will be created', $this->detailOf($requirements, 'requirements.var_dir'));
    }

    #[Test]
    public function it_should_report_a_missing_console(): void
    {
        $requirements = ($this->check(console: '/nowhere/bin/console'))();

        self::assertContains('requirements.console', $this->labelsOf($requirements->unsatisfied()));
    }

    #[Test]
    public function it_should_report_an_unreadable_target_database_dsn(): void
    {
        $requirements = ($this->check(dsn: 'not-a-dsn'))();

        self::assertContains('requirements.target_database', $this->labelsOf($requirements->unsatisfied()));
    }

    #[Test]
    public function it_should_report_a_dump_source_it_cannot_read(): void
    {
        $dumpSource = $this->createStub(DumpSource::class);
        $dumpSource->method('list')->willThrowException(new \RuntimeException('directory is unreadable'));

        $requirements = ($this->check(dumpSource: $dumpSource))();

        self::assertSame('directory is unreadable', $this->detailOf($requirements, 'requirements.dumps'));
    }

    #[Test]
    public function it_should_count_the_available_dumps(): void
    {
        $requirements = ($this->check())();

        self::assertSame('1 dump(s) available.', $this->detailOf($requirements, 'requirements.dumps'));
    }

    #[Test]
    public function it_should_report_proc_open_when_it_is_disabled(): void
    {
        $requirements = ($this->check(disabledFunctions: 'passthru, proc_open, shell_exec'))();

        self::assertContains('requirements.proc_open', $this->labelsOf($requirements->unsatisfied()));
    }

    #[Test]
    public function it_should_accept_a_host_disabling_other_functions(): void
    {
        $requirements = ($this->check(disabledFunctions: 'passthru,shell_exec'))();

        self::assertTrue($requirements->allSatisfied());
    }

    #[Test]
    public function it_should_read_the_disabled_functions_of_the_running_php_by_default(): void
    {
        $requirements = ($this->check(disabledFunctions: null))();

        self::assertTrue($requirements->allSatisfied());
    }

    private function check(
        ?DumpSource $dumpSource = null,
        string $dsn = 'mysql://app:secret@127.0.0.1:3306/app',
        ?string $varDir = null,
        ?string $console = null,
        ?string $phpBinary = '/usr/bin/php',
        ?ExecutableFinder $executableFinder = null,
        ?PhpExecutableFinder $phpExecutableFinder = null,
        ?string $disabledFunctions = '',
        ?MysqlBinary $mysqlBinary = null,
    ): CheckRequirements {
        return new CheckRequirements(
            dumpSource: $dumpSource ?? $this->aDumpSource(),
            targetDatabaseDsn: new TargetDatabaseDsn($dsn),
            varDir: $varDir ?? sys_get_temp_dir(),
            console: $console ?? __FILE__,
            mysqlBinary: $mysqlBinary ?? new MysqlBinary('mysql', $this->finderFindingEverything()),
            phpBinary: $phpBinary,
            executableFinder: $executableFinder ?? $this->finderFindingEverything(),
            phpExecutableFinder: $phpExecutableFinder ?? new PhpExecutableFinder(),
            disabledFunctions: $disabledFunctions,
        );
    }

    private function aDumpSource(): DumpSource
    {
        $dumpSource = $this->createStub(DumpSource::class);
        $dumpSource->method('list')->willReturn(new Dumps(
            Dump::fromPath('dumps/prod.sql.gz', FileSize::fromBytes(1024), new \DateTimeImmutable('2026-09-18 08:00:00')),
        ));

        return $dumpSource;
    }

    private function finderFindingEverything(): ExecutableFinder
    {
        $executableFinder = $this->createStub(ExecutableFinder::class);
        $executableFinder->method('find')->willReturnCallback(static fn (string $name): string => '/usr/bin/'.$name);

        return $executableFinder;
    }

    private function finderWithout(string ...$missing): ExecutableFinder
    {
        $executableFinder = $this->createStub(ExecutableFinder::class);
        $executableFinder->method('find')->willReturnCallback(
            static fn (string $name): ?string => \in_array($name, $missing, true) ? null : '/usr/bin/'.$name,
        );

        return $executableFinder;
    }

    /**
     * @param list<Requirement> $requirements
     *
     * @return list<string>
     */
    private function labelsOf(array $requirements): array
    {
        return array_map(static fn (Requirement $requirement): string => $requirement->label, $requirements);
    }

    /**
     * @param iterable<Requirement> $requirements
     */
    private function detailOf(iterable $requirements, string $label): string
    {
        foreach ($requirements as $requirement) {
            if ($label === $requirement->label) {
                return $requirement->detail;
            }
        }

        self::fail(\sprintf('No requirement labelled "%s".', $label));
    }
}
