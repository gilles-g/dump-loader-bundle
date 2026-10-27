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

final readonly class Percentage
{
    private function __construct(
        public float $value,
    ) {
    }

    public static function of(float $value): self
    {
        if ($value < 0.0 || $value > 100.0) {
            throw new \InvalidArgumentException(\sprintf('A percentage lies between 0 and 100, %s given.', $value));
        }

        return new self($value);
    }

    public static function zero(): self
    {
        return new self(0.0);
    }

    public static function complete(): self
    {
        return new self(100.0);
    }

    public function toInt(): int
    {
        return (int) round($this->value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
