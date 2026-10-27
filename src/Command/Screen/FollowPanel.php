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
use DumpLoader\LoadJob\FollowedLoadJob;
use DumpLoader\LoadJob\LoadJobStatus;
use DumpLoader\LoadJob\LogLine;
use Psr\Clock\ClockInterface;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\LoaderWidget;
use Symfony\Component\Tui\Widget\ProgressBarWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

use function Symfony\Component\String\u;

final class FollowPanel
{
    private const VISIBLE_LOG_LINES = 12;
    private const POLL_INTERVAL_SECONDS = 0.25;

    private float $sinceLastPoll = 0.0;

    public function __construct(
        private readonly FollowedLoadJob $job,
        private readonly Labels $labels,
        private readonly ClockInterface $clock,
        private readonly LoaderWidget $spinner = new LoaderWidget(),
        private readonly ProgressBarWidget $bar = new ProgressBarWidget(100, ' [%bar%] %percent:3s%%'),
        private readonly TextWidget $log = new TextWidget(truncate: true),
    ) {
    }

    public function mountOn(Tui $tui): void
    {
        $tui->add(new TextWidget(\sprintf('Loading "%s" — Ctrl+C aborts the load job.', StringUtils::stripControlBytes($this->job->snapshot()->dumpName))))
            ->add($this->spinner)
            ->add($this->bar)
            ->add($this->log)
        ;

        $this->spinner->start();
        $this->show();
    }

    public function tick(float $deltaTime): void
    {
        $this->sinceLastPoll += $deltaTime;

        if ($this->sinceLastPoll < self::POLL_INTERVAL_SECONDS) {
            return;
        }

        $this->sinceLastPoll = 0.0;
        $this->job->refresh();
        $this->show();
    }

    public function abort(): void
    {
        $this->job->requestAbort();
        $this->show();
    }

    public function isOver(): bool
    {
        return $this->job->isOver();
    }

    private function show(): void
    {
        $snapshot = $this->job->snapshot();
        $this->spinner->setMessage(\sprintf('%s — %s', $this->activity(), $snapshot->elapsedAt($this->clock->now())->minutesAndSeconds()));
        $this->bar->setProgress($snapshot->progress->overall()->toInt());

        if ($this->job->isOver()) {
            $this->spinner->setFinishedIndicator(LoadJobStatus::Completed === $snapshot->status ? '✔' : '✘');
            $this->spinner->stop();
        }

        $lines = array_map(
            static fn (LogLine $line): string => \sprintf('%-5s %s', $line->tag->value, StringUtils::stripControlBytes($line->message)),
            $this->job->newLogLines(),
        );

        if ([] === $lines) {
            return;
        }

        $shown = '' === $this->log->getText() ? [] : array_map(strval(...), u($this->log->getText())->split("\n"));
        $this->log->setText(implode("\n", \array_slice([...$shown, ...$lines], -self::VISIBLE_LOG_LINES)));
    }

    private function activity(): string
    {
        $snapshot = $this->job->snapshot();

        if ($this->job->isOver()) {
            return ucfirst($snapshot->status->value);
        }

        if ($this->job->isAborting()) {
            return 'Aborting…';
        }

        $step = $snapshot->runningStep();

        return null === $step ? 'Waiting for the load job to start…' : StringUtils::stripControlBytes($this->labels->translate($step->label));
    }
}
