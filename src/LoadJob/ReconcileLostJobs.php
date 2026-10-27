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

namespace DumpLoader\LoadJob;

final readonly class ReconcileLostJobs
{
    public function __construct(
        private LoadJobRepository $jobs,
        private VanishedJobs $vanished,
        private JobLock $lock,
    ) {
    }

    public function __invoke(): void
    {
        foreach ($this->jobs->active() as $snapshot) {
            if ($this->vanished->includes($snapshot)) {
                $this->markLost($snapshot->id);
            }
        }
    }

    private function markLost(LoadJobId $id): void
    {
        if (!$this->lock->acquire($id)) {
            return;
        }

        try {
            $job = $this->jobs->get($id);

            if ($this->vanished->suspects($job->snapshot())) {
                $job->markLost();
                $this->jobs->save($job);
            }
        } finally {
            $this->lock->release($id);
        }
    }
}
