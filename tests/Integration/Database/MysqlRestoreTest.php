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
use DumpLoader\Database\Mysql\MysqlClient;
use DumpLoader\Database\Mysql\MysqlRestorePolicy;
use DumpLoader\Database\TargetDatabase;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\Flysystem\FlysystemDumpSource;
use DumpLoader\Dump\GzippedSqlFile;
use DumpLoader\Dump\GzipSqlStream;
use DumpLoader\Dump\LocalDumpFiles;
use DumpLoader\Dump\PlainSqlFile;
use DumpLoader\Dump\PlainSqlStream;
use DumpLoader\Dump\SqlInput;
use DumpLoader\Dump\StreamedSqlInput;
use DumpLoader\Execution\CommandFailed;
use DumpLoader\Execution\Process\SymfonyProcessRunner;
use DumpLoader\LoadJob\AbortSignal;
use DumpLoader\LoadJob\JobLock;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobAborted;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobSnapshot;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\PlannedStep;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\RunLoadJob;
use DumpLoader\LoadJob\RunLoadJobHandler;
use DumpLoader\Step\DropAllTables;
use DumpLoader\Step\RestoreDatabase;
use DumpLoader\Step\Step;
use DumpLoader\Step\StepSequence;
use DumpLoader\Tests\Double\InMemoryLoadJobRepository;
use DumpLoader\Tests\Double\RecordingStepTracker;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[Group('mysql')]
final class MysqlRestoreTest extends TestCase
{
    private const DSN_VARIABLE = 'DUMP_LOADER_TEST_DSN';

    private const BINARY_VARIABLE = 'DUMP_LOADER_TEST_MYSQL_BINARY';

    private const BULK_ROWS = 200_000;

    private const BULK_ROWS_PER_STATEMENT = 100;

    private static string $bulkDump = '';

    private static string $bulkArchive = '';

    private static string $truncatedArchive = '';

    private TargetDatabase $database;

    private \PDO $connection;

    private string $varDir;

    public static function setUpBeforeClass(): void
    {
        self::$bulkDump = tempnam(sys_get_temp_dir(), 'dump-loader-bulk-').'.sql';

        $handle = fopen(self::$bulkDump, 'w');
        self::assertIsResource($handle);

        fwrite($handle, "DROP TABLE IF EXISTS `bulk`;\nCREATE TABLE `bulk` (\n  `id` int NOT NULL,\n  `payload` varchar(255) NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n");

        $payload = str_repeat('x', 200);

        for ($id = 1; $id <= self::BULK_ROWS; $id += self::BULK_ROWS_PER_STATEMENT) {
            $values = [];

            for ($offset = 0; $offset < self::BULK_ROWS_PER_STATEMENT; ++$offset) {
                $values[] = \sprintf("(%d,'%s')", $id + $offset, $payload);
            }

            fwrite($handle, 'INSERT INTO `bulk` VALUES '.implode(',', $values).";\n");
        }

        fclose($handle);

        self::$bulkArchive = self::$bulkDump.'.gz';
        (new Process(['sh', '-c', 'exec gzip -c -1 -- "$1" > "$2"', 'sh', self::$bulkDump, self::$bulkArchive]))->setTimeout(null)->mustRun();

        $archive = (string) file_get_contents(self::$bulkArchive);
        self::$truncatedArchive = self::$bulkDump.'.truncated.gz';
        file_put_contents(self::$truncatedArchive, substr($archive, 0, intdiv(\strlen($archive) * 3, 4)));
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$bulkDump, self::$bulkArchive, self::$truncatedArchive] as $file) {
            if ('' !== $file && is_file($file)) {
                unlink($file);
            }
        }

        self::$bulkDump = '';
        self::$bulkArchive = '';
        self::$truncatedArchive = '';
    }

    protected function setUp(): void
    {
        $server = TargetDatabase::fromDsn($this->dsn());

        $this->database = new TargetDatabase(
            host: $server->host,
            port: $server->port,
            name: 'dump_loader_restore_'.bin2hex(random_bytes(6)),
            user: $server->user,
            password: $server->password,
            charset: $server->charset,
        );

        $this->connection = $this->connect($server->name);
        $this->connection->exec(\sprintf('CREATE DATABASE `%s` DEFAULT CHARACTER SET %s', $this->database->name, $this->database->charset));
        $this->connection = $this->connect($this->database->name);

        $this->varDir = sys_get_temp_dir().'/dump-loader-restore-'.bin2hex(random_bytes(6));
        mkdir($this->varDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->connect('')->exec(\sprintf('DROP DATABASE IF EXISTS `%s`', $this->database->name));

        foreach (glob($this->varDir.'/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->varDir);
    }

    #[Test]
    public function it_should_restore_every_row_of_a_real_mysqldump(): void
    {
        $this->client()->restore($this->database, $this->gzipFixture('mysqldump-shop.sql.gz'), RecordingStepTracker::silent());

        self::assertSame(3, $this->fetchCount('SELECT COUNT(*) FROM `customer`'));
        self::assertSame(4, $this->fetchCount('SELECT COUNT(*) FROM `order`'));
        self::assertSame('zoé@example.com', $this->select('SELECT `email` FROM `customer` WHERE `id` = 3')->fetchColumn());
    }

    #[Test]
    public function it_should_restore_a_blob_byte_for_byte(): void
    {
        $this->client()->restore($this->database, $this->gzipFixture('mysqldump-shop.sql.gz'), RecordingStepTracker::silent());

        $avatar = $this->select('SELECT `avatar` FROM `customer` WHERE `id` = 1')->fetchColumn();

        self::assertIsString($avatar);
        self::assertSame(256, \strlen($avatar));
        self::assertSame(implode('', array_map(chr(...), range(0, 255))), $avatar);
    }

    #[Test]
    public function it_should_restore_a_view_that_stays_queryable(): void
    {
        $this->client()->restore($this->database, $this->gzipFixture('mysqldump-shop.sql.gz'), RecordingStepTracker::silent());

        $revenues = $this->select('SELECT CAST(`revenue_cents` AS CHAR) FROM `customer_revenue` ORDER BY `id`')->fetchAll(\PDO::FETCH_COLUMN);

        self::assertSame(['17450', '78000', '990'], $revenues);
    }

    #[Test]
    public function it_should_restore_a_foreign_key_that_still_rejects_an_orphan_row(): void
    {
        $this->client()->restore($this->database, $this->gzipFixture('mysqldump-shop.sql.gz'), RecordingStepTracker::silent());

        $this->expectException(\PDOException::class);

        $this->connection->exec('INSERT INTO `order` (`customer_id`, `total_cents`) VALUES (999, 100)');
    }

    #[Test]
    public function it_should_refuse_a_dump_targeting_an_explicit_database_before_touching_the_server(): void
    {
        try {
            $this->client()->restore($this->database, $this->plainFixture('mysqldump-shop-databases.sql'), RecordingStepTracker::silent());
            self::fail('The restore should have been refused.');
        } catch (DumpTargetsExplicitDatabase) {
        }

        self::assertSame(0, $this->fetchCount(\sprintf("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '%s'", $this->database->name)));
    }

    #[Test]
    public function it_should_leave_a_partial_database_and_no_connection_behind_when_aborted(): void
    {
        $tracker = RecordingStepTracker::abortingAfterPolls(500);

        try {
            $this->client()->restore($this->database, $this->plainStream(self::$bulkDump), $tracker);
            self::fail('The restore should have been aborted.');
        } catch (LoadJobAborted) {
        }

        $restored = $this->fetchCount('SELECT COUNT(*) FROM `bulk`');

        self::assertGreaterThan(0, $restored, 'The abort happened before the first row was committed.');
        self::assertLessThan(self::BULK_ROWS, $restored, 'The whole dump was restored before the abort was requested.');
        self::assertSame(0, $this->lingeringConnections(), 'A client connection outlived the aborted restore.');
    }

    #[Test]
    public function it_should_restore_a_large_dump_without_growing_its_memory(): void
    {
        $before = memory_get_peak_usage(true);

        $this->client()->restore($this->database, $this->plainStream(self::$bulkDump), RecordingStepTracker::silent());

        self::assertSame(self::BULK_ROWS, $this->fetchCount('SELECT COUNT(*) FROM `bulk`'));
        self::assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    #[Test]
    public function it_should_restore_a_local_plain_file_handed_straight_to_the_client(): void
    {
        $this->client()->restore($this->database, $this->localPlain(self::$bulkDump), RecordingStepTracker::silent());

        self::assertSame(self::BULK_ROWS, $this->fetchCount('SELECT COUNT(*) FROM `bulk`'));
    }

    #[Test]
    public function it_should_restore_a_local_gzipped_file_handed_straight_to_the_client(): void
    {
        $this->client()->restore($this->database, $this->localGzipped(self::$bulkArchive), RecordingStepTracker::silent());

        self::assertSame(self::BULK_ROWS, $this->fetchCount('SELECT COUNT(*) FROM `bulk`'));
    }

    #[Test]
    public function it_should_refuse_a_local_file_that_targets_another_database(): void
    {
        try {
            $this->client()->restore($this->database, $this->localPlain(__DIR__.'/../../Fixtures/dumps/mysqldump-shop-databases.sql'), RecordingStepTracker::silent());
            self::fail('The restore should have been refused.');
        } catch (DumpTargetsExplicitDatabase) {
        }

        self::assertSame(0, $this->fetchCount(\sprintf("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '%s'", $this->database->name)));
    }

    #[Test]
    public function it_should_fail_when_a_local_gzipped_file_is_truncated(): void
    {
        $this->expectException(CommandFailed::class);

        $this->client()->restore($this->database, $this->localGzipped(self::$truncatedArchive), RecordingStepTracker::silent());
    }

    #[Test]
    public function it_should_leave_no_connection_behind_when_a_local_plain_restore_is_aborted(): void
    {
        $tracker = RecordingStepTracker::abortingAfterPolls(500);

        try {
            $this->client()->restore($this->database, $this->localPlain(self::$bulkDump), $tracker);
            self::fail('The restore should have been aborted.');
        } catch (LoadJobAborted) {
        }

        self::assertLessThan(self::BULK_ROWS, $this->fetchCount('SELECT COUNT(*) FROM `bulk`'));
        self::assertSame(0, $this->lingeringConnections(), 'A client connection outlived the aborted restore.');
    }

    #[Test]
    public function it_should_leave_no_connection_behind_when_a_local_gzipped_restore_is_aborted(): void
    {
        $tracker = RecordingStepTracker::abortingAfterPolls(500);

        try {
            $this->client()->restore($this->database, $this->localGzipped(self::$bulkArchive), $tracker);
            self::fail('The restore should have been aborted.');
        } catch (LoadJobAborted) {
        }

        $restored = $this->fetchCount('SELECT COUNT(*) FROM `bulk`');
        self::assertLessThan(self::BULK_ROWS, $restored);
        self::assertSame(0, $this->lingeringConnections(), 'A client connection outlived the aborted restore.');

        sleep(2);
        self::assertSame($restored, $this->fetchCount('SELECT COUNT(*) FROM `bulk`'), 'The pipeline outlived the aborted restore.');
    }

    private function localPlain(string $path): PlainSqlFile
    {
        return new PlainSqlFile($path, $this->binary('sh'));
    }

    private function localGzipped(string $path): GzippedSqlFile
    {
        return new GzippedSqlFile($path, $this->binary('bash'), $this->binary('gzip'));
    }

    #[Test]
    public function it_should_drop_every_table_and_view_of_the_target(): void
    {
        $this->client()->restore($this->database, $this->gzipFixture('mysqldump-shop.sql.gz'), RecordingStepTracker::silent());

        $this->client()->dropAllTables($this->database, RecordingStepTracker::silent());

        self::assertSame(0, $this->remainingRelations());
    }

    #[Test]
    public function it_should_drop_a_table_the_dump_no_longer_carries(): void
    {
        $this->client()->restore($this->database, $this->gzipFixture('mysqldump-shop.sql.gz'), RecordingStepTracker::silent());
        $this->connection->exec('CREATE TABLE `added_by_a_migration` (`id` int NOT NULL, PRIMARY KEY (`id`))');

        $this->client()->dropAllTables($this->database, RecordingStepTracker::silent());
        $this->client()->restore($this->database, $this->gzipFixture('mysqldump-shop.sql.gz'), RecordingStepTracker::silent());

        self::assertSame(0, $this->fetchCount(\sprintf(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME = 'added_by_a_migration'",
            $this->database->name,
        )));
        self::assertSame(3, $this->fetchCount('SELECT COUNT(*) FROM `customer`'));
    }

    #[Test]
    public function it_should_leave_an_already_empty_database_alone(): void
    {
        $this->client()->dropAllTables($this->database, RecordingStepTracker::silent());

        self::assertSame(0, $this->remainingRelations());
    }

    #[Test]
    public function it_should_drop_a_table_and_a_view_whose_names_hold_a_backtick(): void
    {
        $this->connection->exec('CREATE TABLE `weird``name` (`id` int NOT NULL, PRIMARY KEY (`id`))');
        $this->connection->exec('CREATE VIEW `odd``view` AS SELECT `id` FROM `weird``name`');

        $this->client()->dropAllTables($this->database, RecordingStepTracker::silent());

        self::assertSame(0, $this->remainingRelations());
    }

    #[Test]
    public function it_should_keep_every_table_when_a_load_job_refuses_a_dump_targeting_another_database(): void
    {
        $this->client()->restore($this->database, $this->gzipFixture('mysqldump-shop.sql.gz'), RecordingStepTracker::silent());

        $snapshot = $this->runLoadJob('mysqldump-shop-databases.sql');

        self::assertSame(LoadJobStatus::Failed, $snapshot->status);
        self::assertStringContainsString('CREATE DATABASE', (string) $snapshot->failureReason);
        self::assertSame(3, $this->fetchCount('SELECT COUNT(*) FROM `customer`'));
        self::assertSame(4, $this->fetchCount('SELECT COUNT(*) FROM `order`'));
    }

    #[Test]
    public function it_should_replace_the_tables_of_the_target_through_a_load_job(): void
    {
        $this->connection->exec('CREATE TABLE `added_by_a_migration` (`id` int NOT NULL, PRIMARY KEY (`id`))');

        $snapshot = $this->runLoadJob('mysqldump-shop.sql.gz');

        self::assertSame(LoadJobStatus::Completed, $snapshot->status);
        self::assertSame(0, $this->fetchCount(\sprintf(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '%s' AND TABLE_NAME = 'added_by_a_migration'",
            $this->database->name,
        )));
        self::assertSame(3, $this->fetchCount('SELECT COUNT(*) FROM `customer`'));
    }

    private function runLoadJob(string $dumpPath): LoadJobSnapshot
    {
        $fixtures = __DIR__.'/../../Fixtures/dumps';
        $dumpSource = new LocalDumpFiles(new FlysystemDumpSource(new Filesystem(new LocalFilesystemAdapter($fixtures))), $fixtures);
        $client = $this->client();
        $steps = new StepSequence([
            new DropAllTables($dumpSource, new MysqlRestorePolicy(), $client, $this->targetDsn()),
            new RestoreDatabase($dumpSource, $client, $this->targetDsn()),
        ]);
        $clock = new MockClock('2026-09-23 08:00:00');

        $jobs = new InMemoryLoadJobRepository();
        $job = LoadJob::request(
            id: LoadJobId::generate(),
            dump: $dumpSource->find($dumpPath),
            options: LoadOptions::none(),
            steps: array_map(
                static fn (Step $step): PlannedStep => PlannedStep::fromDefinition($step->definition()),
                $steps->plan(LoadOptions::none()),
            ),
            requester: Requester::named('an operator'),
            clock: $clock,
        );
        $jobs->save($job);

        $jobLock = $this->createStub(JobLock::class);
        $jobLock->method('acquire')->willReturn(true);

        (new RunLoadJobHandler($jobs, $steps, $dumpSource, $jobLock, $this->createStub(AbortSignal::class), $clock))(new RunLoadJob($job->id()));

        return $jobs->get($job->id())->snapshot();
    }

    private function remainingRelations(): int
    {
        return $this->fetchCount(\sprintf(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '%s'",
            $this->database->name,
        ));
    }

    private function binary(string $name): string
    {
        $found = (new ExecutableFinder())->find($name);

        if (null === $found) {
            self::markTestSkipped(\sprintf('The "%s" binary is required to hand a local dump to the client.', $name));
        }

        return $found;
    }

    private function client(): MysqlClient
    {
        $binary = getenv(self::BINARY_VARIABLE);

        return new MysqlClient(
            new SymfonyProcessRunner(new Clock()),
            new DefaultsFile($this->varDir),
            new MysqlBinary(\is_string($binary) && '' !== $binary ? $binary : 'mysql'),
        );
    }

    private function targetDsn(): TargetDatabaseDsn
    {
        return new TargetDatabaseDsn(\sprintf(
            'mysql://%s:%s@%s:%d/%s?charset=%s',
            rawurlencode($this->database->user),
            rawurlencode($this->database->password),
            $this->database->host,
            $this->database->port,
            $this->database->name,
            $this->database->charset,
        ));
    }

    private function dsn(): string
    {
        $dsn = getenv(self::DSN_VARIABLE);

        if (!\is_string($dsn) || '' === $dsn) {
            self::fail(\sprintf('The %s environment variable must point at a MySQL server to run the "mysql" group.', self::DSN_VARIABLE));
        }

        return $dsn;
    }

    private function connect(string $name): \PDO
    {
        return new \PDO(
            \sprintf('mysql:host=%s;port=%d;charset=%s', $this->database->host, $this->database->port, $this->database->charset).('' === $name ? '' : ';dbname='.$name),
            $this->database->user,
            $this->database->password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
    }

    private function select(string $sql): \PDOStatement
    {
        $statement = $this->connection->query($sql);

        self::assertInstanceOf(\PDOStatement::class, $statement);

        return $statement;
    }

    private function fetchCount(string $sql): int
    {
        return (int) $this->select($sql)->fetchColumn();
    }

    private function lingeringConnections(): int
    {
        $sql = \sprintf("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB = '%s' AND ID <> CONNECTION_ID()", $this->database->name);

        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $lingering = $this->fetchCount($sql);

            if (0 === $lingering) {
                return 0;
            }

            usleep(100_000);
        }

        return $this->fetchCount($sql);
    }

    private function gzipFixture(string $name): SqlInput
    {
        return new StreamedSqlInput(GzipSqlStream::over($this->open(__DIR__.'/../../Fixtures/dumps/'.$name)));
    }

    private function plainFixture(string $name): SqlInput
    {
        return new StreamedSqlInput(PlainSqlStream::over($this->open(__DIR__.'/../../Fixtures/dumps/'.$name)));
    }

    private function plainStream(string $path): SqlInput
    {
        return new StreamedSqlInput(PlainSqlStream::over($this->open($path)));
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        $handle = fopen($path, 'r');

        self::assertIsResource($handle);

        return $handle;
    }
}
