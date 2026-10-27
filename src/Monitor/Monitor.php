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

use DumpLoader\LoadJob\LoadJobs;
use DumpLoader\LoadJob\LoadJobSnapshot;

final readonly class Monitor
{
    public function __construct(
        public ?LoadJobSnapshot $followedJob,
        public LoadJobs $history,
        public \DateTimeImmutable $seenAt,
        public ?string $unreadableJobDetail = null,
    ) {
    }

    public function isLive(): bool
    {
        return null !== $this->followedJob && $this->followedJob->status->isActive();
    }
}
