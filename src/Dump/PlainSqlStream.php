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

final class PlainSqlStream implements SqlStream
{
    private const DEFAULT_CHUNK_SIZE = 1_048_576;

    private int $rawBytesRead = 0;

    /**
     * @param resource     $source
     * @param positive-int $chunkSize
     */
    private function __construct(
        private $source,
        private readonly int $chunkSize,
    ) {
    }

    /**
     * @param resource     $source
     * @param positive-int $chunkSize
     */
    public static function over($source, int $chunkSize = self::DEFAULT_CHUNK_SIZE): self
    {
        return new self($source, $chunkSize);
    }

    /**
     * @return \Generator<int, string>
     */
    public function getIterator(): \Generator
    {
        try {
            while (!feof($this->source)) {
                $chunk = fread($this->source, $this->chunkSize);
                if (false === $chunk || '' === $chunk) {
                    break;
                }

                $this->rawBytesRead += \strlen($chunk);

                yield $chunk;
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
