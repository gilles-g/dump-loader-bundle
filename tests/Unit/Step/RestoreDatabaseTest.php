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
use DumpLoader\Database\TargetDatabase;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\Dump;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\FileSize;
use DumpLoader\Dump\SqlInput;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\Step\LoadContext;
use DumpLoader\Step\RestoreDatabase;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepDefinition;
use DumpLoader\Step\StepTracker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RestoreDatabaseTest extends TestCase
{
    #[Test]
    public function it_should_be_a_mandatory_step_ranked_before_the_others(): void
    {
        $step = new RestoreDatabase(
            $this->createStub(DumpSource::class),
            $this->createStub(DatabaseClient::class),
            self::targetDsn(),
        );

        $definition = $step->definition();

        self::assertInstanceOf(Step::class, $step);
        self::assertSame('restore_database', $definition->name);
        self::assertSame(StepDefinition::RANK_RESTORE_DATABASE, $definition->rank);
        self::assertSame(10, $definition->weight);
    }

    #[Test]
    public function it_should_hand_the_stream_of_the_requested_dump_to_the_database_client(): void
    {
        $dump = self::dump();
        $input = $this->createStub(SqlInput::class);
        $tracker = $this->createMock(StepTracker::class);
        $target = self::target();

        $source = $this->createMock(DumpSource::class);
        $source->expects(self::once())->method('open')->with($dump)->willReturn($input);

        $client = $this->createMock(DatabaseClient::class);
        $client->expects(self::once())->method('restore')->with($target, $input, $tracker);

        $step = new RestoreDatabase($source, $client, self::targetDsn());
        $step->run(self::context($dump), $tracker);
    }

    private static function context(Dump $dump): LoadContext
    {
        return new LoadContext(LoadJobId::generate(), $dump, LoadOptions::none());
    }

    private static function dump(): Dump
    {
        return Dump::fromPath('2026-09-18.sql.gz', FileSize::fromBytes(2048), new \DateTimeImmutable('2026-09-18 10:00:00'));
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
