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

namespace DumpLoader\Dump\Flysystem;

use DumpLoader\Dump\Dump;
use DumpLoader\Dump\DumpNotFound;
use DumpLoader\Dump\Dumps;
use DumpLoader\Dump\DumpSource;
use DumpLoader\Dump\FileSize;
use DumpLoader\Dump\SqlInput;
use DumpLoader\Dump\StreamedSqlInput;
use DumpLoader\Dump\UnsupportedDumpFormat;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;

final readonly class FlysystemDumpSource implements DumpSource
{
    public function __construct(
        private FilesystemOperator $filesystem,
        private string $directory = '',
    ) {
    }

    public function list(): Dumps
    {
        $dumps = [];

        foreach ($this->filesystem->listContents($this->directory, false) as $attributes) {
            $dump = $this->describe($attributes);

            if (null !== $dump) {
                $dumps[] = $dump;
            }
        }

        usort($dumps, static fn (Dump $left, Dump $right): int => $right->modifiedAt <=> $left->modifiedAt);

        return new Dumps(...$dumps);
    }

    public function find(string $path): Dump
    {
        foreach ($this->list() as $dump) {
            if ($dump->path === $path) {
                return $dump;
            }
        }

        throw DumpNotFound::atPath($path);
    }

    public function open(Dump $dump): SqlInput
    {
        try {
            $stream = $this->filesystem->readStream($dump->path);
        } catch (FilesystemException) {
            throw DumpNotFound::atPath($dump->path);
        }

        return new StreamedSqlInput($dump->format->open($stream));
    }

    private function describe(StorageAttributes $attributes): ?Dump
    {
        if (!$attributes->isFile()) {
            return null;
        }

        try {
            return Dump::fromPath(
                $attributes->path(),
                FileSize::fromBytes($this->filesystem->fileSize($attributes->path())),
                new \DateTimeImmutable('@'.$this->filesystem->lastModified($attributes->path())),
            );
        } catch (UnsupportedDumpFormat|FilesystemException) {
            return null;
        }
    }
}
