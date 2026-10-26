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

namespace DumpLoader\Tests\Double;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Clock\MockClock;

final class ScriptedClock implements ClockInterface
{
    /**
     * @var list<\Closure(): void>
     */
    private array $script = [];

    private int $sleeps = 0;

    public function __construct(
        private readonly MockClock $clock = new MockClock('2026-09-18 08:00:00'),
    ) {
    }

    public function onSleep(\Closure ...$steps): self
    {
        $this->script = [...$this->script, ...array_values($steps)];

        return $this;
    }

    public function now(): DatePoint
    {
        return $this->clock->now();
    }

    public function sleep(float|int $seconds): void
    {
        $this->clock->sleep($seconds);

        $step = $this->script[$this->sleeps] ?? throw new \LogicException(\sprintf('The script has no step for sleep #%d.', $this->sleeps + 1));
        ++$this->sleeps;

        $step();
    }

    public function withTimeZone(\DateTimeZone|string $timezone): static
    {
        return $this;
    }

    public function sleeps(): int
    {
        return $this->sleeps;
    }
}
