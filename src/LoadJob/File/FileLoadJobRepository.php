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

namespace DumpLoader\LoadJob\File;

use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobNotFound;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadJobs;
use DumpLoader\LoadJob\LoadJobSnapshot;
use DumpLoader\LoadJob\UnreadableLoadJob;
use Psr\Clock\ClockInterface;

final readonly class FileLoadJobRepository implements LoadJobRepository
{
    public function __construct(
        private string $directory,
        private ClockInterface $clock,
    ) {
    }

    public function save(LoadJob $job): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o775, true) && !is_dir($this->directory)) {
            throw JobDirectoryNotWritable::at($this->directory);
        }

        $snapshot = $job->snapshot();
        $path = $this->pathFor($snapshot->id);
        $temporaryPath = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $json = json_encode($snapshot->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if (false === @file_put_contents($temporaryPath, $json) || !@rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw JobDirectoryNotWritable::at($this->directory);
        }
    }

    public function get(LoadJobId $id): LoadJob
    {
        $path = $this->pathFor($id);

        if (!is_file($path)) {
            throw LoadJobNotFound::withId($id);
        }

        return LoadJob::fromSnapshot($this->read($path), $this->clock);
    }

    public function active(): LoadJobs
    {
        return new LoadJobs(...array_filter(
            $this->mostRecentFirst($this->presentSnapshots()),
            static fn (LoadJobSnapshot $snapshot): bool => $snapshot->status->isActive(),
        ));
    }

    public function history(int $limit): LoadJobs
    {
        return new LoadJobs(...\array_slice($this->mostRecentFirst($this->readableSnapshots()), 0, max(0, $limit)));
    }

    public function prune(int $keep): void
    {
        $finished = array_filter(
            $this->mostRecentFirst($this->readableSnapshots()),
            static fn (LoadJobSnapshot $snapshot): bool => !$snapshot->status->isActive(),
        );

        foreach (\array_slice($finished, max(0, $keep)) as $snapshot) {
            $this->forget($snapshot->id);
        }
    }

    /**
     * @return list<string>
     */
    private function paths(): array
    {
        return glob($this->directory.'/*.json') ?: [];
    }

    /**
     * @return list<LoadJobSnapshot>
     */
    private function presentSnapshots(): array
    {
        $snapshots = [];

        foreach ($this->paths() as $path) {
            try {
                $snapshots[] = $this->read($path);
            } catch (UnreadableLoadJob $unreadable) {
                if (is_file($path)) {
                    throw $unreadable;
                }
            }
        }

        return $snapshots;
    }

    /**
     * @return list<LoadJobSnapshot>
     */
    private function readableSnapshots(): array
    {
        $snapshots = [];

        foreach ($this->paths() as $path) {
            try {
                $snapshots[] = $this->read($path);
            } catch (UnreadableLoadJob) {
            }
        }

        return $snapshots;
    }

    /**
     * @param list<LoadJobSnapshot> $snapshots
     *
     * @return list<LoadJobSnapshot>
     */
    private function mostRecentFirst(array $snapshots): array
    {
        usort(
            $snapshots,
            static fn (LoadJobSnapshot $left, LoadJobSnapshot $right): int => $right->requestedAt <=> $left->requestedAt,
        );

        return $snapshots;
    }

    private function read(string $path): LoadJobSnapshot
    {
        $contents = @file_get_contents($path);

        if (false === $contents) {
            throw UnreadableLoadJob::at($path, 'the file cannot be opened');
        }

        try {
            $data = json_decode($contents, true, 512, \JSON_THROW_ON_ERROR);

            if (!\is_array($data)) {
                throw UnreadableLoadJob::at($path, 'it does not hold a JSON object');
            }

            return LoadJobSnapshot::fromArray($data);
        } catch (\JsonException|\InvalidArgumentException|\ValueError $exception) {
            throw UnreadableLoadJob::at($path, $exception->getMessage(), $exception);
        }
    }

    private function forget(LoadJobId $id): void
    {
        $snapshotPath = $this->pathFor($id);

        foreach (glob($this->directory.'/'.$id->value.'.*') ?: [] as $path) {
            if ($path !== $snapshotPath) {
                @unlink($path);
            }
        }

        @unlink($snapshotPath);
    }

    private function pathFor(LoadJobId $id): string
    {
        return $this->directory.'/'.$id->value.'.json';
    }
}
