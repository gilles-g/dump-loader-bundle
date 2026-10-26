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

use Psr\Clock\ClockInterface;

final readonly class VanishedJobs
{
    public function __construct(
        private JobLock $lock,
        private ClockInterface $clock,
        private int $pendingGracePeriod,
    ) {
        if ($this->pendingGracePeriod < 0) {
            throw new \InvalidArgumentException(\sprintf('A pending grace period cannot be negative, %d given.', $this->pendingGracePeriod));
        }
    }

    public function includes(LoadJobSnapshot $snapshot): bool
    {
        return $this->suspects($snapshot) && $this->lock->isFree($snapshot->id);
    }

    public function suspects(LoadJobSnapshot $snapshot): bool
    {
        if (!$snapshot->status->isActive()) {
            return false;
        }

        if (LoadJobStatus::Pending !== $snapshot->status) {
            return true;
        }

        return $this->clock->now()->getTimestamp() - $snapshot->requestedAt->getTimestamp() >= $this->pendingGracePeriod;
    }

    public function seenNow(LoadJobSnapshot $snapshot): LoadJobSnapshot
    {
        if (!$this->includes($snapshot)) {
            return $snapshot;
        }

        $job = LoadJob::fromSnapshot($snapshot, $this->clock);
        $job->markLost();

        return $job->snapshot();
    }
}
