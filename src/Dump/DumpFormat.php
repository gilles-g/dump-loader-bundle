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

enum DumpFormat: string
{
    case Sql = 'sql';
    case GzippedSql = 'sql.gz';

    public static function fromPath(string $path): self
    {
        $lowered = u($path)->lower();

        foreach (self::cases() as $format) {
            $suffix = '.'.$format->value;
            if ($lowered->endsWith($suffix) && $lowered->length() > \strlen($suffix)) {
                return $format;
            }
        }

        throw UnsupportedDumpFormat::forPath($path);
    }

    /**
     * @param resource $source
     */
    public function open($source): SqlStream
    {
        return match ($this) {
            self::Sql => PlainSqlStream::over($source),
            self::GzippedSql => GzipSqlStream::over($source),
        };
    }

    public function header(string $path, int $window): string
    {
        $handle = @fopen($path, 'r');

        if (false === $handle) {
            throw DumpNotFound::atPath($path);
        }

        $header = '';

        foreach ($this->open($handle) as $chunk) {
            $header .= $chunk;

            if (\strlen($header) >= $window) {
                break;
            }
        }

        return $header;
    }
}
