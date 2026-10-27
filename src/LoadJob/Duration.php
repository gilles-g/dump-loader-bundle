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

final readonly class Duration
{
    private function __construct(
        public int $seconds,
    ) {
    }

    public static function between(\DateTimeImmutable $start, \DateTimeImmutable $end): self
    {
        return new self(max(0, $end->getTimestamp() - $start->getTimestamp()));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function humanized(): string
    {
        if ($this->seconds < 60) {
            return \sprintf('%d s', $this->seconds);
        }

        if ($this->seconds < 3600) {
            return \sprintf('%d min %02d s', intdiv($this->seconds, 60), $this->seconds % 60);
        }

        return \sprintf('%d h %02d min', intdiv($this->seconds, 3600), intdiv($this->seconds % 3600, 60));
    }

    public function minutesAndSeconds(): string
    {
        return \sprintf('%d:%02d', intdiv($this->seconds, 60), $this->seconds % 60);
    }
}
