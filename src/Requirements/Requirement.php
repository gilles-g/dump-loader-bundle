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

namespace DumpLoader\Requirements;

final readonly class Requirement
{
    private function __construct(
        public string $label,
        public bool $satisfied,
        public string $detail,
    ) {
    }

    public static function satisfied(string $label, string $detail = ''): self
    {
        return new self($label, true, $detail);
    }

    public static function missing(string $label, string $detail): self
    {
        return new self($label, false, $detail);
    }
}
