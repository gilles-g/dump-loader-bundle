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

namespace DumpLoader\Dump;

final readonly class FileSize
{
    private function __construct(
        public int $bytes,
    ) {
    }

    public static function fromBytes(int $bytes): self
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException(\sprintf('A file size cannot be negative, got %d bytes.', $bytes));
        }

        return new self($bytes);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function humanized(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $this->bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < \count($units) - 1) {
            $size /= 1024;
            ++$unit;
        }

        return \sprintf(0 === $unit ? '%d %s' : '%.1f %s', $size, $units[$unit]);
    }
}
