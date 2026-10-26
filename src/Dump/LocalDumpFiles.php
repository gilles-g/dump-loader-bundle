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

use Symfony\Component\Process\ExecutableFinder;

final readonly class LocalDumpFiles implements DumpSource
{
    public function __construct(
        private DumpSource $dumps,
        private string $directory,
        private ExecutableFinder $executableFinder = new ExecutableFinder(),
    ) {
    }

    public function list(): Dumps
    {
        return $this->dumps->list();
    }

    public function find(string $path): Dump
    {
        return $this->dumps->find($path);
    }

    public function open(Dump $dump): SqlInput
    {
        return $this->handOver($dump) ?? $this->dumps->open($dump);
    }

    private function handOver(Dump $dump): ?SqlInput
    {
        $path = $this->directory.'/'.$dump->path;

        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        return match ($dump->format) {
            DumpFormat::Sql => $this->redirected($path),
            DumpFormat::GzippedSql => $this->decompressed($path),
        };
    }

    private function redirected(string $path): ?SqlInput
    {
        $shell = $this->executableFinder->find('sh');

        return null === $shell ? null : new PlainSqlFile($path, $shell);
    }

    private function decompressed(string $path): ?SqlInput
    {
        $shell = $this->executableFinder->find('bash');
        $decompressor = $this->executableFinder->find('gzip');

        return null === $shell || null === $decompressor ? null : new GzippedSqlFile($path, $shell, $decompressor);
    }
}
