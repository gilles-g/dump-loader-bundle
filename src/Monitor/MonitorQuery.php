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

namespace DumpLoader\Monitor;

use DumpLoader\LoadJob\LoadJobRepository;
use DumpLoader\LoadJob\LoadJobs;
use DumpLoader\LoadJob\UnreadableLoadJob;
use DumpLoader\LoadJob\VanishedJobs;
use Psr\Clock\ClockInterface;

final readonly class MonitorQuery
{
    public function __construct(
        private LoadJobRepository $jobs,
        private VanishedJobs $vanished,
        private int $historyLimit,
        private ClockInterface $clock,
    ) {
    }

    public function current(): Monitor
    {
        return $this->following($this->jobs->history($this->historyLimit)->seenThrough($this->vanished));
    }

    public function progress(): Monitor
    {
        return $this->following($this->jobs->history(1)->seenThrough($this->vanished));
    }

    private function following(LoadJobs $history): Monitor
    {
        try {
            $activeJob = $this->jobs->active()->seenThrough($this->vanished)->firstActive();
        } catch (UnreadableLoadJob $unreadable) {
            return new Monitor(followedJob: null, history: $history, seenAt: $this->clock->now(), unreadableJobDetail: $unreadable->getMessage());
        }

        return new Monitor(followedJob: $activeJob ?? $history->first(), history: $history, seenAt: $this->clock->now());
    }
}
