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

namespace DumpLoader\Tests\Double;

use DumpLoader\Database\DatabaseClient;
use DumpLoader\Database\TargetDatabase;
use DumpLoader\Dump\FileSize;
use DumpLoader\Dump\SqlInput;
use DumpLoader\Execution\ShellCommand;
use DumpLoader\LoadJob\LoadJobAborted;
use DumpLoader\LoadJob\LogTag;
use DumpLoader\LoadJob\Percentage;
use DumpLoader\Step\StepTracker;

final class InMemoryDatabaseClient implements DatabaseClient
{
    private string $restoredSql = '';

    private int $bytesRestored = 0;

    private ?TargetDatabase $restoredInto = null;

    private ?TargetDatabase $droppedFrom = null;

    private function __construct(
        private readonly ?\Throwable $failure,
        private readonly int $microsecondsPerChunk,
    ) {
    }

    public static function accepting(): self
    {
        return new self(failure: null, microsecondsPerChunk: 0);
    }

    public static function failingWith(\Throwable $failure): self
    {
        return new self(failure: $failure, microsecondsPerChunk: 0);
    }

    public static function takingPerChunk(int $microseconds): self
    {
        return new self(failure: null, microsecondsPerChunk: $microseconds);
    }

    public function restore(TargetDatabase $database, SqlInput $input, StepTracker $tracker): void
    {
        $this->restoredInto = $database;
        $tracker->log(LogTag::Run, \sprintf('Restoring into %s.', $database->displayName()));

        foreach ($input->feed(new ShellCommand(argv: ['mysql']))->input as $chunk) {
            if (0 < $this->microsecondsPerChunk) {
                usleep($this->microsecondsPerChunk);
            }

            if ($tracker->abortRequested()) {
                throw LoadJobAborted::onRequest();
            }

            $this->restoredSql .= $chunk;
            $this->bytesRestored += \strlen($chunk);
        }

        if (null !== $this->failure) {
            throw $this->failure;
        }

        $tracker->progress(Percentage::complete());
    }

    public function dropAllTables(TargetDatabase $database, StepTracker $tracker): void
    {
        $this->droppedFrom = $database;
        $tracker->log(LogTag::Run, \sprintf('Dropping every table of %s.', $database->displayName()));
    }

    public function droppedFrom(): ?TargetDatabase
    {
        return $this->droppedFrom;
    }

    public function restoredSql(): string
    {
        return $this->restoredSql;
    }

    public function restoredSize(): FileSize
    {
        return FileSize::fromBytes($this->bytesRestored);
    }

    public function restoredInto(): ?TargetDatabase
    {
        return $this->restoredInto;
    }
}
