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

namespace DumpLoader\Dump;

use function Symfony\Component\String\u;

final readonly class Dump
{
    private function __construct(
        public string $path,
        public string $name,
        public FileSize $size,
        public \DateTimeImmutable $modifiedAt,
        public DumpFormat $format,
    ) {
    }

    public static function fromPath(string $path, FileSize $size, \DateTimeImmutable $modifiedAt): self
    {
        return new self(
            path: $path,
            name: u($path)->afterLast('/')->toString() ?: $path,
            size: $size,
            modifiedAt: $modifiedAt,
            format: DumpFormat::fromPath($path),
        );
    }
}
