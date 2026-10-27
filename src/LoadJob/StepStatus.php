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

enum StepStatus: string
{
    case Todo = 'todo';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Aborted = 'aborted';

    public function isUnfinished(): bool
    {
        return self::Todo === $this || self::Running === $this;
    }
}
