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

namespace DumpLoader\Tests\Unit\Step;

use DumpLoader\Database\DatabaseClient;
use DumpLoader\Database\RestorePolicy;
use DumpLoader\Database\TargetDatabase;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\CorruptedDump;
use DumpLoader\Dump\Dump;
use DumpLoader\Dump\DumpNotFound;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\FileSize;
use DumpLoader\Dump\SqlInput;
use DumpLoader\Execution\CommandFailed;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Step\DropAllTables;
use DumpLoader\Step\LoadContext;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepFailed;
use DumpLoader\Step\StepTracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DropAllTablesTest extends TestCase
{
    #[Test]
    public function it_should_be_a_mandatory_step_ranked_before_the_restore(): void
    {
        $step = $this->step($this->createStub(DatabaseClient::class));

        $definition = $step->definition();

        self::assertInstanceOf(Step::class, $step);
        self::assertSame('drop_all_tables', $definition->name);
        self::assertSame(StepDefinition::RANK_DROP_ALL_TABLES, $definition->rank);
        self::assertLessThan(StepDefinition::RANK_RESTORE_DATABASE, $definition->rank);
        self::assertSame(1, $definition->weight);
    }

    #[Test]
    public function it_should_ask_the_database_client_to_empty_the_target(): void
    {
        $tracker = $this->createMock(StepTracker::class);
        $target = self::target();

        $databaseClient = $this->createMock(DatabaseClient::class);
        $databaseClient->expects(self::once())->method('dropAllTables')->with($target, $tracker);

        new DropAllTables($this->dumpSource(), $this->createStub(RestorePolicy::class), $databaseClient, self::targetDsn())->run(self::context(), $tracker);
    }

    #[Test]
    public function it_should_check_the_dump_it_is_about_to_restore(): void
    {
        $context = self::context();
        $input = $this->createStub(SqlInput::class);

        $dumpSource = $this->createStub(DumpSource::class);
        $dumpSource->method('open')->willReturnMap([[$context->dump, $input]]);

        $restorePolicy = $this->createMock(RestorePolicy::class);
        $restorePolicy->expects(self::once())->method('ensureRestorable')->with($input);

        new DropAllTables($dumpSource, $restorePolicy, $this->createStub(DatabaseClient::class), self::targetDsn())->run($context, $this->createStub(StepTracker::class));
    }

    #[Test]
    public function it_should_leave_the_target_untouched_when_the_dump_is_not_restorable(): void
    {
        $restorePolicy = $this->createStub(RestorePolicy::class);
        $restorePolicy->method('ensureRestorable')->willThrowException(CorruptedDump::undecodable());

        $databaseClient = $this->createMock(DatabaseClient::class);
        $databaseClient->expects(self::never())->method('dropAllTables');

        $this->expectException(CorruptedDump::class);

        new DropAllTables($this->dumpSource(), $restorePolicy, $databaseClient, self::targetDsn())->run(self::context(), $this->createStub(StepTracker::class));
    }

    #[Test]
    public function it_should_leave_the_target_untouched_when_the_dump_has_vanished(): void
    {
        $dumpSource = $this->createStub(DumpSource::class);
        $dumpSource->method('open')->willThrowException(DumpNotFound::atPath('2026-09-18.sql.gz'));

        $databaseClient = $this->createMock(DatabaseClient::class);
        $databaseClient->expects(self::never())->method('dropAllTables');

        $this->expectException(DumpNotFound::class);

        new DropAllTables($dumpSource, $this->createStub(RestorePolicy::class), $databaseClient, self::targetDsn())->run(self::context(), $this->createStub(StepTracker::class));
    }

    #[Test]
    public function it_should_fail_the_step_when_the_tables_cannot_be_dropped(): void
    {
        $databaseClient = $this->createStub(DatabaseClient::class);
        $databaseClient->method('dropAllTables')->willThrowException(
            CommandFailed::withExitCode(1, ['ERROR 1142 (42000): DROP command denied']),
        );

        $step = $this->step($databaseClient);

        $this->expectException(StepFailed::class);
        $this->expectExceptionMessage('db:3306/app could not be emptied.');

        $step->run(self::context(), $this->createStub(StepTracker::class));
    }

    private function step(DatabaseClient $databaseClient): DropAllTables
    {
        return new DropAllTables($this->dumpSource(), $this->createStub(RestorePolicy::class), $databaseClient, self::targetDsn());
    }

    private function dumpSource(): DumpSource
    {
        $dumpSource = $this->createStub(DumpSource::class);
        $dumpSource->method('open')->willReturn($this->createStub(SqlInput::class));

        return $dumpSource;
    }

    private static function context(): LoadContext
    {
        $dump = Dump::fromPath('2026-09-18.sql.gz', FileSize::fromBytes(2048), new \DateTimeImmutable('2026-09-18 10:00:00'));

        return new LoadContext(LoadJobId::generate(), $dump, LoadOptions::none());
    }

    private static function targetDsn(): TargetDatabaseDsn
    {
        return new TargetDatabaseDsn('mysql://root:secret@db:3306/app');
    }

    private static function target(): TargetDatabase
    {
        return new TargetDatabase(
            host: 'db',
            port: 3306,
            name: 'app',
            user: 'root',
            password: 'secret',
            charset: 'utf8mb4',
        );
    }
}
