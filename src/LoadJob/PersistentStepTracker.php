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

use DumpLoader\Step\StepTracker;
use Psr\Clock\ClockInterface;

final class PersistentStepTracker implements StepTracker
{
    private const float MIN_SECONDS_BETWEEN_SAVES = 0.5;

    private ?float $lastSavedAt = null;

    public function __construct(
        private readonly LoadJob $job,
        private readonly LoadJobRepository $jobs,
        private readonly AbortSignal $abortSignal,
        private readonly ClockInterface $clock,
    ) {
    }

    public function log(LogTag $tag, string $message): void
    {
        $this->job->log($tag, $message);
        $this->persist();
    }

    public function progress(Percentage $percentage): void
    {
        $this->job->progress($percentage);
        $this->persist();
    }

    public function abortRequested(): bool
    {
        return $this->abortSignal->isRaised($this->job->id());
    }

    private function persist(): void
    {
        $now = (float) $this->clock->now()->format('U.u');

        if (null !== $this->lastSavedAt && $now - $this->lastSavedAt < self::MIN_SECONDS_BETWEEN_SAVES) {
            return;
        }

        $this->lastSavedAt = $now;
        $this->jobs->save($this->job);
    }
}
