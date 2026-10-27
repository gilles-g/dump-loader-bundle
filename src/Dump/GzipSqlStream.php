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

final class GzipSqlStream implements SqlStream
{
    private const DEFAULT_READ_SIZE = 131_072;
    private const DEFAULT_CHUNK_SIZE = 1_048_576;

    private int $rawBytesRead = 0;

    /**
     * @param resource     $source
     * @param positive-int $readSize
     * @param positive-int $chunkSize
     */
    private function __construct(
        private $source,
        private readonly int $readSize,
        private readonly int $chunkSize,
    ) {
    }

    /**
     * @param resource     $source
     * @param positive-int $readSize
     * @param positive-int $chunkSize
     */
    public static function over($source, int $readSize = self::DEFAULT_READ_SIZE, int $chunkSize = self::DEFAULT_CHUNK_SIZE): self
    {
        return new self($source, $readSize, $chunkSize);
    }

    /**
     * @return \Generator<int, string>
     */
    public function getIterator(): \Generator
    {
        $context = null;
        $consumedByContext = 0;
        $memberEnded = false;
        $buffer = '';

        try {
            while (!feof($this->source)) {
                $raw = fread($this->source, $this->readSize);
                if (false === $raw || '' === $raw) {
                    break;
                }

                $this->rawBytesRead += \strlen($raw);

                $pending = $raw;
                while ('' !== $pending) {
                    if (null === $context || $memberEnded) {
                        $context = inflate_init(\ZLIB_ENCODING_GZIP);
                        if (false === $context) {
                            throw CorruptedDump::undecodable();
                        }
                        $consumedByContext = 0;
                        $memberEnded = false;
                    }

                    $decoded = @inflate_add($context, $pending, \ZLIB_NO_FLUSH);
                    if (false === $decoded) {
                        throw CorruptedDump::undecodable();
                    }

                    $buffer .= $decoded;

                    $readLength = inflate_get_read_len($context);
                    $pending = substr($pending, $readLength - $consumedByContext);
                    $consumedByContext = $readLength;

                    if (\ZLIB_STREAM_END !== inflate_get_status($context)) {
                        break;
                    }

                    $memberEnded = true;
                }

                while (\strlen($buffer) >= $this->chunkSize) {
                    yield substr($buffer, 0, $this->chunkSize);
                    $buffer = substr($buffer, $this->chunkSize);
                }
            }

            if (!$memberEnded) {
                throw CorruptedDump::truncated();
            }

            if ('' !== $buffer) {
                yield $buffer;
            }
        } finally {
            if (\is_resource($this->source)) {
                fclose($this->source);
            }
        }
    }

    public function rawBytesRead(): FileSize
    {
        return FileSize::fromBytes($this->rawBytesRead);
    }
}
