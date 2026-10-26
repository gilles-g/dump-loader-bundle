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

interface LoadJobRepository
{
    public function save(LoadJob $job): void;

    public function get(LoadJobId $id): LoadJob;

    public function active(): LoadJobs;

    public function history(int $limit): LoadJobs;

    public function prune(int $keep): void;
}
