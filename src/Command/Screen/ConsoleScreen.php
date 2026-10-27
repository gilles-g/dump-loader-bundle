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

namespace DumpLoader\Command\Screen;

use DumpLoader\Command\Labels;
use DumpLoader\Dump\Dump;
use DumpLoader\Dump\Dumps;
use DumpLoader\LoadJob\FollowedLoadJob;
use DumpLoader\LoadJob\LoadOptions;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class ConsoleScreen implements LoadDumpScreen
{
    private const float POLL_INTERVAL_SECONDS = 0.5;

    public function __construct(
        private SymfonyStyle $io,
        private ClockInterface $clock,
        private Labels $labels,
    ) {
    }

    public function chooseDump(Dumps $dumps): string
    {
        $choices = [];

        foreach ($dumps as $dump) {
            $choices[$dump->path] = $this->describe($dump);
        }

        $path = $this->io->choice('Which dump do you want to load?', $choices);
        \assert(\is_string($path));

        return $path;
    }

    public function chooseOptions(array $availableOptions, LoadOptions $defaultOptions): LoadOptions
    {
        $chosen = [];

        foreach ($availableOptions as $option) {
            if ($this->io->confirm($this->labels->translate($option->label), $defaultOptions->includes($option->name))) {
                $chosen[] = $option->name;
            }
        }

        return LoadOptions::fromNames($chosen);
    }

    public function confirm(string $database): bool
    {
        return $this->io->confirm(\sprintf('Every table of "%s" will be dropped before the restore. Continue?', $database), false);
    }

    public function follow(FollowedLoadJob $job): void
    {
        $bar = $this->io->createProgressBar(100);
        $bar->setFormat(' [%bar%] %percent:3s%% %message%');

        $this->show($job, $bar);

        while (!$job->isOver()) {
            $this->clock->sleep(self::POLL_INTERVAL_SECONDS);
            $job->refresh();
            $this->show($job, $bar);
        }

        $this->io->newLine(2);
    }

    private function show(FollowedLoadJob $job, ProgressBar $bar): void
    {
        $lines = $job->newLogLines();

        if ([] !== $lines) {
            $bar->clear();

            foreach ($lines as $line) {
                $this->io->writeln(\sprintf('<comment>%-5s</comment> %s', $line->tag->value, OutputFormatter::escape($line->message)));
            }
        }

        $snapshot = $job->snapshot();
        $bar->setMessage($job->isAborting() ? 'Aborting…' : $this->labels->translate($snapshot->runningStep()->label ?? ''));
        $bar->setProgress($snapshot->progress->overall()->toInt());
        $bar->display();
    }

    private function describe(Dump $dump): string
    {
        return \sprintf('%s (%s, %s)', $dump->name, $dump->size->humanized(), $dump->modifiedAt->format('Y-m-d H:i'));
    }
}
