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

final readonly class AbortLoadJobHandler
{
    public function __construct(
        private LoadJobRepository $jobs,
        private AbortSignal $abortSignal,
    ) {
    }

    public function __invoke(AbortLoadJob $command): void
    {
        $status = $this->jobs->get($command->id)->snapshot()->status;

        if ($status->isFinished()) {
            throw InvalidTransition::from($status, 'be aborted');
        }

        $this->abortSignal->request($command->id);
    }
}
