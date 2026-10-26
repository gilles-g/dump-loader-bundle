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

enum LoadJobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Aborted = 'aborted';
    case Lost = 'lost';

    public function isActive(): bool
    {
        return self::Pending === $this || self::Running === $this;
    }

    public function isFinished(): bool
    {
        return !$this->isActive();
    }
}
