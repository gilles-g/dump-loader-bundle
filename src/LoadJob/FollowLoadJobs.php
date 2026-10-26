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

final readonly class FollowLoadJobs
{
    public function __construct(
        private LoadJobRepository $jobs,
        private VanishedJobs $vanished,
        private AbortLoadJobHandler $abort,
    ) {
    }

    public function follow(LoadJobId $id): FollowedLoadJob
    {
        return new FollowedLoadJob(
            $this->jobs,
            $this->vanished,
            $this->abort,
            $this->vanished->seenNow($this->jobs->get($id)->snapshot()),
        );
    }
}
