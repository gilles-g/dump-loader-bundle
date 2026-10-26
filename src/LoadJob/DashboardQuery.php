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

namespace DumpLoader\LoadJob;

use DumpLoader\Database\InvalidDsn;
use DumpLoader\Database\TargetDatabaseDsn;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Step\StepSequence;

final readonly class DashboardQuery
{
    public function __construct(
        private DumpSource $dumps,
        private StepSequence $steps,
        private LoadJobRepository $jobs,
        private VanishedJobs $vanished,
        private TargetDatabaseDsn $targetDatabaseDsn,
        private int $historyLimit,
    ) {
    }

    public function current(): Dashboard
    {
        $activeJob = null;
        $unreadableJobDetail = null;

        try {
            $activeJob = $this->jobs->active()->seenThrough($this->vanished)->firstActive();
        } catch (UnreadableLoadJob $unreadable) {
            $unreadableJobDetail = $unreadable->getMessage();
        }

        return Dashboard::fromCurrentState(
            dumps: $this->dumps->list(),
            options: $this->steps->availableOptions(),
            activeJob: $activeJob,
            unreadableJobDetail: $unreadableJobDetail,
            history: $this->jobs->history($this->historyLimit)->seenThrough($this->vanished),
            targetDatabase: $this->targetDatabaseName(),
        );
    }

    private function targetDatabaseName(): ?string
    {
        try {
            return $this->targetDatabaseDsn->targetDatabase()->displayName();
        } catch (InvalidDsn) {
            return null;
        }
    }
}
