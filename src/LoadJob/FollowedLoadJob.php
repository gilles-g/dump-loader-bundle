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

final class FollowedLoadJob
{
    private int $renderedLines = 0;

    private bool $aborting = false;

    public function __construct(
        private readonly LoadJobRepository $jobs,
        private readonly VanishedJobs $vanished,
        private readonly AbortLoadJobHandler $abort,
        private LoadJobSnapshot $snapshot,
    ) {
    }

    public function refresh(): void
    {
        $this->snapshot = $this->vanished->seenNow($this->jobs->get($this->snapshot->id)->snapshot());
    }

    public function snapshot(): LoadJobSnapshot
    {
        return $this->snapshot;
    }

    public function isOver(): bool
    {
        return $this->snapshot->status->isFinished();
    }

    public function isAborting(): bool
    {
        return $this->aborting;
    }

    /**
     * @return list<LogLine>
     */
    public function newLogLines(): array
    {
        $log = $this->snapshot->log;
        $lines = \array_slice($log->lines, max(0, $this->renderedLines - $log->omittedLines));
        $this->renderedLines = $log->omittedLines + \count($log->lines);

        return $lines;
    }

    public function requestAbort(): void
    {
        if ($this->aborting || $this->isOver()) {
            return;
        }

        $this->aborting = true;

        try {
            ($this->abort)(new AbortLoadJob($this->snapshot->id));
        } catch (InvalidTransition) {
        }
    }
}
