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

namespace DumpLoader\Database\Mysql;

final class DefaultsFileNotWritable extends \RuntimeException
{
    public static function in(string $directory): self
    {
        return new self(\sprintf('The MySQL credentials file could not be written in "%s": make the directory writable by the PHP user.', $directory));
    }

    public static function lineBreakIn(string $field): self
    {
        return new self(\sprintf('Cannot write a line break into the MySQL credentials file: remove it from the %s of the target database DSN.', $field));
    }
}
