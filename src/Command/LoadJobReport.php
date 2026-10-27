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

namespace DumpLoader\Command;

use DumpLoader\LoadJob\LoadJobSnapshot;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\PlannedStep;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class LoadJobReport
{
    public function __construct(
        private LoadJobSnapshot $snapshot,
        private Labels $labels,
    ) {
    }

    public function writeTo(SymfonyStyle $io): void
    {
        $io->section(\sprintf('Load job %s', $this->snapshot->id->value));
        $io->definitionList(
            ['Status' => $this->snapshot->status->value],
            ['Dump' => OutputFormatter::escape($this->snapshot->dumpName)],
            ['Options' => [] === $this->snapshot->options->names ? 'none' : implode(', ', $this->snapshot->options->names)],
            ['Duration' => $this->duration()],
        );
        $io->table(['Step', 'Status'], array_map(
            fn (PlannedStep $step): array => [
                OutputFormatter::escape($this->labels->translate($step->label)),
                $this->labels->translate('step_status.'.$step->status->value),
            ],
            $this->snapshot->steps,
        ));

        match ($this->snapshot->status) {
            LoadJobStatus::Completed => $io->success('The dump is loaded.'),
            LoadJobStatus::Aborted => $io->warning('The load job was aborted on request.'),
            LoadJobStatus::Failed => $io->error($this->snapshot->failureReason ?? 'The load job failed.'),
            LoadJobStatus::Lost => $io->error('The load job was lost: its process vanished.'),
            LoadJobStatus::Pending, LoadJobStatus::Running => $io->note('The load job is still running, follow it in the monitor.'),
        };
    }

    public function isSuccessful(): bool
    {
        return LoadJobStatus::Completed === $this->snapshot->status;
    }

    private function duration(): string
    {
        if (null === $this->snapshot->startedAt || null === $this->snapshot->finishedAt) {
            return '-';
        }

        return Helper::formatTime($this->snapshot->elapsedAt($this->snapshot->finishedAt)->seconds);
    }
}
