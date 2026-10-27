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

namespace DumpLoader\Execution\Process;

use DumpLoader\Execution\LoadJobLauncher;
use DumpLoader\LoadJob\LoadJobId;
use DumpLoader\LoadJob\RunLoadJob;
use DumpLoader\LoadJob\RunLoadJobHandler;

final readonly class SynchronousLauncher implements LoadJobLauncher
{
    public function __construct(
        private RunLoadJobHandler $run,
    ) {
    }

    public function launch(LoadJobId $id): void
    {
        ($this->run)(new RunLoadJob($id));
    }
}
