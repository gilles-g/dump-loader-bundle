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

use DumpLoader\Execution\ShellCommand;

final class StreamedSqlInput implements SqlInput, SqlStream
{
    private string $buffer = '';

    /**
     * @var \Iterator<int, string>|null
     */
    private ?\Iterator $chunks = null;

    public function __construct(
        private readonly SqlStream $stream,
    ) {
    }

    /**
     * @phpstan-impure
     */
    public function header(int $window): string
    {
        $chunks = $this->chunks();

        while ($chunks->valid() && \strlen($this->buffer) < $window) {
            $this->buffer .= $chunks->current();
            $chunks->next();
        }

        return $this->buffer;
    }

    public function feed(ShellCommand $command): ShellCommand
    {
        return $command->withInput($this);
    }

    /**
     * @return \Generator<int, string>
     */
    public function getIterator(): \Generator
    {
        $chunks = $this->chunks();

        if ('' !== $this->buffer) {
            yield $this->buffer;
        }

        while ($chunks->valid()) {
            yield $chunks->current();
            $chunks->next();
        }
    }

    public function rawBytesRead(): FileSize
    {
        return $this->stream->rawBytesRead();
    }

    /**
     * @return \Iterator<int, string>
     */
    private function chunks(): \Iterator
    {
        if (null === $this->chunks) {
            $this->chunks = new \IteratorIterator($this->stream->getIterator());
            $this->chunks->rewind();
        }

        return $this->chunks;
    }
}
