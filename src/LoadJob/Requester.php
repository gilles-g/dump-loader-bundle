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

use function Symfony\Component\String\u;

final readonly class Requester
{
    private function __construct(
        public ?string $name,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null);
    }

    public static function named(string $name): self
    {
        if (u($name)->trim()->isEmpty()) {
            throw new \InvalidArgumentException('A named requester needs a name.');
        }

        return new self($name);
    }

    public function isKnown(): bool
    {
        return null !== $this->name;
    }
}
