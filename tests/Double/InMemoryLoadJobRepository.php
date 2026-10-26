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

namespace DumpLoader\Tests\Double;

use DumpLoader\LoadJob\LoadJob;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\LoadJobNotFound;
use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadJobs;
use DumpLoader\LoadJob\LoadJobSnapshot;

final class InMemoryLoadJobRepository implements LoadJobRepository
{
    /**
     * @var array<string, LoadJob>
     */
    private array $jobs = [];

    public function save(LoadJob $job): void
    {
        $this->jobs[$job->id()->value] = $job;
    }

    public function get(LoadJobId $id): LoadJob
    {
        return $this->jobs[$id->value] ?? throw LoadJobNotFound::withId($id);
    }

    public function active(): LoadJobs
    {
        return new LoadJobs(...array_values(array_filter(
            $this->snapshots(),
            static fn (LoadJobSnapshot $snapshot): bool => $snapshot->status->isActive(),
        )));
    }

    public function history(int $limit): LoadJobs
    {
        return new LoadJobs(...\array_slice(array_reverse($this->snapshots()), 0, $limit));
    }

    public function prune(int $keep): void
    {
        $finished = array_filter($this->jobs, static fn (LoadJob $job): bool => !$job->snapshot()->status->isActive());

        foreach (array_keys(\array_slice(array_reverse($finished, true), max(0, $keep), null, true)) as $id) {
            unset($this->jobs[$id]);
        }
    }

    public function latest(): LoadJobSnapshot
    {
        $snapshots = $this->snapshots();

        if ([] === $snapshots) {
            throw new \LogicException('No load job was saved.');
        }

        return $snapshots[\count($snapshots) - 1];
    }

    /**
     * @return list<LoadJobSnapshot>
     */
    private function snapshots(): array
    {
        return array_values(array_map(static fn (LoadJob $job): LoadJobSnapshot => $job->snapshot(), $this->jobs));
    }
}
