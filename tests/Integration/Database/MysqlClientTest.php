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

namespace DumpLoader\Tests\Integration\Database;

use DumpLoader\Database\Mysql\DefaultsFile;
use DumpLoader\Database\Mysql\DumpTargetsExplicitDatabase;
use DumpLoader\Database\Mysql\MysqlBinary;
use DumpLoader\Database\Mysql\MysqlBinaryNotFound;
use DumpLoader\Database\Mysql\MysqlClient;
use DumpLoader\Database\TargetDatabase;
use DumpLoader\Dump\PlainSqlStream;
use DumpLoader\Dump\SqlInput;
use DumpLoader\Dump\StreamedSqlInput;
use DumpLoader\Execution\CommandFailed;
use DumpLoader\Execution\ProcessRunner;
use DumpLoader\Execution\ShellCommand;
use DumpLoader\Execution\ShellCommandResult;
use DumpLoader\Step\StepTracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MysqlClientTest extends TestCase
{
    private string $varDir;

    private string $binary;

    protected function setUp(): void
    {
        $this->varDir = sys_get_temp_dir().'/dump-loader-mysql-client-'.bin2hex(random_bytes(6));
        mkdir($this->varDir, 0o777, true);

        $this->binary = $this->varDir.'/fake-mysql';
        file_put_contents($this->binary, "#!/bin/sh\nwc -c\n");
        chmod($this->binary, 0o755);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->varDir.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->varDir);
    }

    #[Test]
    public function it_should_pipe_every_decompressed_byte_to_the_mysql_binary(): void
    {
        $sql = '';
        $runner = $this->runnerCapturing($command, $sql);

        $this->client($runner)->restore($this->database(), $this->stream('INSERT INTO t VALUES (1);SELECT 1;'), $this->createStub(StepTracker::class));

        self::assertSame('INSERT INTO t VALUES (1);SELECT 1;', $sql);
    }

    #[Test]
    public function it_should_put_the_defaults_extra_file_first_and_scope_the_command_to_the_target_database(): void
    {
        $sql = '';
        $runner = $this->runnerCapturing($command, $sql);

        $this->client($runner)->restore($this->database(), $this->stream('SELECT 1;'), $this->createStub(StepTracker::class));

        self::assertInstanceOf(ShellCommand::class, $command);
        self::assertSame($this->binary, $command->argv[0]);
        self::assertStringStartsWith('--defaults-extra-file='.$this->varDir.'/', $command->argv[1]);
        self::assertContains('--one-database', $command->argv);
        self::assertContains('--max-allowed-packet=1G', $command->argv);
        self::assertSame('shop', $command->argv[\count($command->argv) - 1]);
    }

    #[Test]
    public function it_should_hand_the_credentials_to_the_client_in_a_file_deleted_after_the_restore(): void
    {
        $contentDuringRun = null;
        $modeDuringRun = null;
        $runner = $this->runnerReadingTheDefaultsFile($contentDuringRun, $modeDuringRun);

        $this->client($runner)->restore($this->database(), $this->stream('SELECT 1;'), $this->createStub(StepTracker::class));

        self::assertIsString($contentDuringRun);
        self::assertStringContainsString('password = "s3cr#t"', $contentDuringRun);
        self::assertSame(0o600, $modeDuringRun);
        self::assertSame([], glob($this->varDir.'/'.DefaultsFile::PREFIX.'*'));
    }

    #[Test]
    public function it_should_never_expose_the_secret_in_the_command_line(): void
    {
        $sql = '';
        $runner = $this->runnerCapturing($command, $sql);

        $this->client($runner)->restore($this->database(), $this->stream('SELECT 1;'), $this->createStub(StepTracker::class));

        self::assertInstanceOf(ShellCommand::class, $command);
        self::assertStringNotContainsString('s3cr#t', $command->display());
    }

    #[Test]
    public function it_should_delete_the_defaults_file_when_the_command_fails(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->method('run')->willReturn(new ShellCommandResult(exitCode: 1, errorOutput: ['ERROR 1045 (28000): Access denied']));

        try {
            $this->client($runner)->restore($this->database(), $this->stream('SELECT 1;'), $this->createStub(StepTracker::class));
            self::fail('The restore should have failed.');
        } catch (CommandFailed $exception) {
            self::assertStringContainsString('Access denied', $exception->getMessage());
        }

        self::assertSame([], glob($this->varDir.'/'.DefaultsFile::PREFIX.'*'));
    }

    #[DataProvider('dumpsTargetingAnExplicitDatabase')]
    #[Test]
    public function it_should_refuse_a_dump_targeting_an_explicit_database(string $firstChunk): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects(self::never())->method('run');

        $this->expectException(DumpTargetsExplicitDatabase::class);

        $this->client($runner)->restore($this->database(), $this->stream($firstChunk), $this->createStub(StepTracker::class));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function dumpsTargetingAnExplicitDatabase(): iterable
    {
        yield 'create database' => ["-- MySQL dump\nCREATE DATABASE /*!32312 IF NOT EXISTS*/ `other`;\n"];
        yield 'use' => ["-- MySQL dump\nUSE `other`;\nINSERT INTO t VALUES (1);"];
        yield 'lowercase create database' => ["create database `other`;\n"];
    }

    #[Test]
    public function it_should_accept_a_dump_mentioning_the_database_in_its_comments_only(): void
    {
        $sql = '';
        $runner = $this->runnerCapturing($command, $sql);

        $header = "-- MySQL dump 10.13\n-- Host: db    Database: shop\n-- The CREATE DATABASE statement is not emitted here.\nDROP TABLE IF EXISTS `t`;\n";

        $this->client($runner)->restore($this->database(), $this->stream($header), $this->createStub(StepTracker::class));

        self::assertSame($header, $sql);
    }

    #[Test]
    public function it_should_fail_when_no_mysql_binary_is_found(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects(self::never())->method('run');

        $client = new MysqlClient($runner, new DefaultsFile($this->varDir), new MysqlBinary($this->varDir.'/absent-mysql'));

        $this->expectException(MysqlBinaryNotFound::class);

        $client->restore($this->database(), $this->stream('SELECT 1;'), $this->createStub(StepTracker::class));
    }

    #[Test]
    public function it_should_pipe_a_dump_larger_than_the_header_window(): void
    {
        $sql = '';
        $runner = $this->runnerCapturing($command, $sql);
        $dump = 'INSERT INTO t VALUES (1);'.str_repeat("-- padding\n", 2000);

        $this->client($runner)->restore($this->database(), $this->stream($dump), $this->createStub(StepTracker::class));

        self::assertSame($dump, $sql);
        self::assertGreaterThan(8192, \strlen($sql));
    }

    #[Test]
    public function it_should_drop_views_before_tables_with_the_foreign_key_checks_off(): void
    {
        $sql = '';
        $runner = $this->runnerCapturing($command, $sql);

        $this->client($runner)->dropAllTables($this->database(), $this->createStub(StepTracker::class));

        self::assertInstanceOf(ShellCommand::class, $command);
        self::assertSame($this->binary, $command->argv[0]);
        self::assertStringStartsWith('--defaults-extra-file='.$this->varDir.'/', $command->argv[1]);
        self::assertSame('shop', $command->argv[\count($command->argv) - 1]);
        self::assertStringContainsString('SET FOREIGN_KEY_CHECKS = 0;', $sql);
        self::assertLessThan(
            strpos($sql, 'DROP TABLE IF EXISTS '),
            strpos($sql, 'DROP VIEW IF EXISTS '),
        );
        self::assertStringContainsString('SET FOREIGN_KEY_CHECKS = 1;', $sql);
    }

    #[Test]
    public function it_should_scope_the_drop_to_the_target_database_only(): void
    {
        $sql = '';
        $runner = $this->runnerCapturing($command, $sql);

        $this->client($runner)->dropAllTables($this->database(), $this->createStub(StepTracker::class));

        self::assertStringContainsString('TABLE_SCHEMA = DATABASE()', $sql);
        self::assertStringNotContainsString('DROP DATABASE', $sql);
    }

    #[Test]
    public function it_should_delete_the_defaults_file_when_the_drop_fails(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->method('run')->willReturn(new ShellCommandResult(exitCode: 1, errorOutput: ['ERROR 1142 (42000): DROP command denied']));

        try {
            $this->client($runner)->dropAllTables($this->database(), $this->createStub(StepTracker::class));
            self::fail('The drop should have failed.');
        } catch (CommandFailed $exception) {
            self::assertStringContainsString('DROP command denied', $exception->getMessage());
        }

        self::assertSame([], glob($this->varDir.'/'.DefaultsFile::PREFIX.'*'));
    }

    #[Test]
    public function it_should_never_expose_the_secret_in_the_drop_command_line(): void
    {
        $sql = '';
        $runner = $this->runnerCapturing($command, $sql);

        $this->client($runner)->dropAllTables($this->database(), $this->createStub(StepTracker::class));

        self::assertInstanceOf(ShellCommand::class, $command);
        self::assertStringNotContainsString('s3cr#t', $command->display());
    }

    #[Test]
    public function it_should_fail_to_drop_when_no_mysql_binary_is_found(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects(self::never())->method('run');

        $client = new MysqlClient($runner, new DefaultsFile($this->varDir), new MysqlBinary($this->varDir.'/absent-mysql'));

        $this->expectException(MysqlBinaryNotFound::class);

        $client->dropAllTables($this->database(), $this->createStub(StepTracker::class));
    }

    private function client(ProcessRunner $runner): MysqlClient
    {
        return new MysqlClient($runner, new DefaultsFile($this->varDir), new MysqlBinary($this->binary));
    }

    private function database(): TargetDatabase
    {
        return new TargetDatabase(host: 'db', port: 3307, name: 'shop', user: 'app', password: 's3cr#t', charset: 'utf8mb4');
    }

    private function stream(string $sql): SqlInput
    {
        $source = fopen('php://memory', 'r+');
        self::assertIsResource($source);
        fwrite($source, $sql);
        rewind($source);

        return new StreamedSqlInput(PlainSqlStream::over($source, 16));
    }

    private function runnerReadingTheDefaultsFile(?string &$content, ?int &$mode): ProcessRunner
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->method('run')->willReturnCallback(
            static function (ShellCommand $shellCommand) use (&$content, &$mode): ShellCommandResult {
                $file = substr($shellCommand->argv[1], \strlen('--defaults-extra-file='));
                $content = file_get_contents($file);
                $mode = fileperms($file) & 0o777;

                foreach ($shellCommand->input as $chunk) {
                    unset($chunk);
                }

                return new ShellCommandResult(exitCode: 0, errorOutput: []);
            }
        );

        return $runner;
    }

    private function runnerCapturing(?ShellCommand &$command, string &$sql): ProcessRunner
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->method('run')->willReturnCallback(
            static function (ShellCommand $shellCommand) use (&$command, &$sql): ShellCommandResult {
                $command = $shellCommand;

                foreach ($shellCommand->input as $chunk) {
                    $sql .= $chunk;
                }

                return new ShellCommandResult(exitCode: 0, errorOutput: []);
            }
        );

        return $runner;
    }
}
