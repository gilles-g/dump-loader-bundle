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

namespace DumpLoader\Tests\Integration\LoadJob\File;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\FileSize;
use DumpLoader\LoadJob\File\FileLoadJobRepository;
use DumpLoader\LoadJob\File\JobDirectoryNotWritable;
use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobNotFound;
use DumpLoader\LoadJob\LoadJobSnapshot;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LoadOptions;
use DumpLoader\LoadJob\PlannedStep;
use DumpLoader\LoadJob\Requester;
use DumpLoader\LoadJob\StepStatus;
use DumpLoader\LoadJob\UnreadableLoadJob;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;

final class FileLoadJobRepositoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/dump-loader-repository-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->directory);
    }

    #[Test]
    public function it_should_store_and_reload_a_job(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);
        $job = $this->job('01J8Z9P0000000000000000001', $clock);
        $job->start();

        $repository->save($job);
        $reloaded = $repository->get($job->snapshot()->id);

        self::assertEquals($job->snapshot(), $reloaded->snapshot());
    }

    #[Test]
    public function it_should_create_the_directory_on_first_save(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);

        $repository->save($this->job('01J8Z9P0000000000000000001', $clock));

        self::assertFileExists($this->directory.'/01J8Z9P0000000000000000001.json');
    }

    #[Test]
    public function it_should_leave_no_temporary_file_behind(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);

        $repository->save($this->job('01J8Z9P0000000000000000001', $clock));
        $repository->save($this->job('01J8Z9P0000000000000000001', $clock));

        self::assertSame(['01J8Z9P0000000000000000001.json'], $this->fileNames());
    }

    #[Test]
    public function it_should_refuse_to_read_an_unknown_job(): void
    {
        $repository = new FileLoadJobRepository($this->directory, new MockClock('2026-09-18 08:00:00'));

        $this->expectException(LoadJobNotFound::class);
        $repository->get(LoadJobId::fromString('01J8Z9P0000000000000000009'));
    }

    #[Test]
    public function it_should_keep_only_active_jobs_in_the_active_list(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);

        $pending = $this->job('01J8Z9P0000000000000000001', $clock);
        $running = $this->job('01J8Z9P0000000000000000002', $clock);
        $running->start();
        $completed = $this->job('01J8Z9P0000000000000000003', $clock);
        $completed->start();
        $completed->complete();

        foreach ([$pending, $running, $completed] as $job) {
            $repository->save($job);
        }

        $ids = array_map(
            static fn (LoadJobSnapshot $snapshot): string => $snapshot->id->value,
            iterator_to_array($repository->active()),
        );

        sort($ids);
        self::assertSame(['01J8Z9P0000000000000000001', '01J8Z9P0000000000000000002'], $ids);
    }

    #[Test]
    public function it_should_return_the_most_recent_jobs_first_in_the_history(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);

        $repository->save($this->job('01J8Z9P0000000000000000001', $clock));
        $clock->sleep(60);
        $repository->save($this->job('01J8Z9P0000000000000000002', $clock));
        $clock->sleep(60);
        $repository->save($this->job('01J8Z9P0000000000000000003', $clock));

        $ids = array_map(
            static fn (LoadJobSnapshot $snapshot): string => $snapshot->id->value,
            iterator_to_array($repository->history(2)),
        );

        self::assertSame(['01J8Z9P0000000000000000003', '01J8Z9P0000000000000000002'], $ids);
    }

    #[Test]
    public function it_should_forget_every_file_of_the_oldest_finished_jobs_beyond_the_retention(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);

        foreach (['01J8Z9P0000000000000000001', '01J8Z9P0000000000000000002', '01J8Z9P0000000000000000003'] as $id) {
            $this->saveWithCompanions($repository, $this->completedJob($id, $clock));
            $clock->sleep(60);
        }

        $repository->prune(2);

        self::assertSame([
            '01J8Z9P0000000000000000002.abort',
            '01J8Z9P0000000000000000002.json',
            '01J8Z9P0000000000000000002.lock',
            '01J8Z9P0000000000000000002.log',
            '01J8Z9P0000000000000000003.abort',
            '01J8Z9P0000000000000000003.json',
            '01J8Z9P0000000000000000003.lock',
            '01J8Z9P0000000000000000003.log',
        ], $this->fileNames());
    }

    #[Test]
    public function it_should_never_forget_an_active_job(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);

        $running = $this->job('01J8Z9P0000000000000000001', $clock);
        $running->start();
        $this->saveWithCompanions($repository, $running);
        $clock->sleep(60);
        $repository->save($this->completedJob('01J8Z9P0000000000000000002', $clock));
        $clock->sleep(60);
        $repository->save($this->completedJob('01J8Z9P0000000000000000003', $clock));

        $repository->prune(1);

        self::assertSame([
            '01J8Z9P0000000000000000001.abort',
            '01J8Z9P0000000000000000001.json',
            '01J8Z9P0000000000000000001.lock',
            '01J8Z9P0000000000000000001.log',
            '01J8Z9P0000000000000000003.json',
        ], $this->fileNames());
    }

    #[Test]
    public function it_should_leave_an_unreadable_job_file_in_place_when_pruning(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);
        $repository->save($this->completedJob('01J8Z9P0000000000000000001', $clock));
        file_put_contents($this->directory.'/01J8Z9P0000000000000000002.json', 'not json at all');

        $repository->prune(0);

        self::assertSame(['01J8Z9P0000000000000000002.json'], $this->fileNames());
    }

    #[Test]
    public function it_should_skip_a_job_file_that_vanished_while_listing_the_active_jobs(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);
        $repository->save($this->job('01J8Z9P0000000000000000001', $clock));
        symlink($this->directory.'/pruned-meanwhile', $this->directory.'/01J8Z9P0000000000000000002.json');

        $ids = array_map(
            static fn (LoadJobSnapshot $snapshot): string => $snapshot->id->value,
            iterator_to_array($repository->active()),
        );

        self::assertSame(['01J8Z9P0000000000000000001'], $ids);
    }

    #[Test]
    #[DataProvider('unreadableContents')]
    public function it_should_leave_an_unreadable_job_file_out_of_the_history(string $contents): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);
        $repository->save($this->job('01J8Z9P0000000000000000001', $clock));

        file_put_contents($this->directory.'/01J8Z9P0000000000000000002.json', $contents);

        $ids = array_map(
            static fn (LoadJobSnapshot $snapshot): string => $snapshot->id->value,
            iterator_to_array($repository->history(10)),
        );

        self::assertSame(['01J8Z9P0000000000000000001'], $ids);
    }

    #[Test]
    #[DataProvider('unreadableContents')]
    public function it_should_refuse_to_list_the_active_jobs_while_a_job_file_is_unreadable(string $contents): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);
        $repository->save($this->job('01J8Z9P0000000000000000001', $clock));

        file_put_contents($this->directory.'/01J8Z9P0000000000000000002.json', $contents);

        $this->expectException(UnreadableLoadJob::class);
        $this->expectExceptionMessage($this->directory.'/01J8Z9P0000000000000000002.json');

        $repository->active();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreadableContents(): iterable
    {
        yield 'not json' => ['not json at all'];
        yield 'a json scalar' => ['"running"'];
        yield 'another format version' => ['{"version": 2, "id": "01J8Z9P0000000000000000002", "status": "running"}'];
        yield 'a malformed snapshot' => ['{"version": 1, "id": 42}'];
    }

    #[Test]
    public function it_should_refuse_to_list_the_active_jobs_while_a_job_file_cannot_be_opened(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);
        $repository->save($this->job('01J8Z9P0000000000000000001', $clock));
        chmod($this->directory.'/01J8Z9P0000000000000000001.json', 0o000);

        $this->expectException(UnreadableLoadJob::class);
        $this->expectExceptionMessage('cannot be opened');

        $repository->active();
    }

    #[Test]
    public function it_should_tell_a_corrupted_job_from_an_unknown_one(): void
    {
        $repository = new FileLoadJobRepository($this->directory, new MockClock('2026-09-18 08:00:00'));
        mkdir($this->directory, 0o775, true);
        file_put_contents($this->directory.'/01J8Z9P0000000000000000001.json', 'not json at all');

        try {
            $repository->get(LoadJobId::fromString('01J8Z9P0000000000000000001'));
            self::fail('A corrupted job should not be read.');
        } catch (UnreadableLoadJob $unreadable) {
            self::assertInstanceOf(\JsonException::class, $unreadable->getPrevious());
        }
    }

    #[Test]
    public function it_should_read_nothing_from_a_missing_directory(): void
    {
        $repository = new FileLoadJobRepository($this->directory, new MockClock('2026-09-18 08:00:00'));

        self::assertTrue($repository->active()->isEmpty());
        self::assertTrue($repository->history(10)->isEmpty());
    }

    #[Test]
    public function it_should_reload_a_job_it_can_keep_mutating(): void
    {
        $clock = new MockClock('2026-09-18 08:00:00');
        $repository = new FileLoadJobRepository($this->directory, $clock);
        $job = $this->job('01J8Z9P0000000000000000001', $clock);
        $repository->save($job);

        $reloaded = $repository->get($job->snapshot()->id);
        $reloaded->start();
        $repository->save($reloaded);

        self::assertSame(LoadJobStatus::Running, $repository->get($job->snapshot()->id)->snapshot()->status);
    }

    #[Test]
    public function it_should_refuse_to_save_in_a_directory_it_cannot_create(): void
    {
        $repository = new FileLoadJobRepository('/proc/nowhere/dump-loader', new MockClock('2026-09-18 08:00:00'));

        $this->expectException(JobDirectoryNotWritable::class);

        $repository->save($this->job('01J8Z9P0000000000000000001', new MockClock('2026-09-18 08:00:00')));
    }

    #[Test]
    public function it_should_refuse_to_save_a_job_it_cannot_write(): void
    {
        mkdir($this->directory, 0o500, true);
        $repository = new FileLoadJobRepository($this->directory, new MockClock('2026-09-18 08:00:00'));

        try {
            $this->expectException(JobDirectoryNotWritable::class);

            $repository->save($this->job('01J8Z9P0000000000000000001', new MockClock('2026-09-18 08:00:00')));
        } finally {
            chmod($this->directory, 0o775);
        }
    }

    private function job(string $id, ClockInterface $clock): LoadJob
    {
        return LoadJob::request(
            id: LoadJobId::fromString($id),
            dump: Dump::fromPath('dumps/prod.sql.gz', FileSize::fromBytes(1_048_576), new \DateTimeImmutable('2026-09-18 07:00:00')),
            options: LoadOptions::fromNames(['clear_caches']),
            steps: [new PlannedStep(name: 'restore_database', label: 'Restore database', weight: 10, status: StepStatus::Todo)],
            requester: Requester::unknown(),
            clock: $clock,
        );
    }

    private function completedJob(string $id, ClockInterface $clock): LoadJob
    {
        $job = $this->job($id, $clock);
        $job->start();
        $job->complete();

        return $job;
    }

    private function saveWithCompanions(FileLoadJobRepository $repository, LoadJob $job): void
    {
        $repository->save($job);

        foreach (['lock', 'log', 'abort'] as $extension) {
            touch($this->directory.'/'.$job->id()->value.'.'.$extension);
        }
    }

    /**
     * @return list<string>
     */
    private function fileNames(): array
    {
        $names = array_values(array_diff(scandir($this->directory) ?: [], ['.', '..']));
        sort($names);

        return $names;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            unlink($directory.'/'.$entry);
        }

        rmdir($directory);
    }
}
