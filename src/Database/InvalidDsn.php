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

namespace DumpLoader\Database;

final class InvalidDsn extends \InvalidArgumentException
{
    public static function unreadable(): self
    {
        return new self('The target database DSN is not a readable URL.');
    }

    public static function unsupportedScheme(string $scheme): self
    {
        return new self(\sprintf('The target database scheme "%s" is not supported, expected one of "%s".', $scheme, implode('", "', TargetDatabase::SUPPORTED_SCHEMES)));
    }

    public static function missingHost(): self
    {
        return new self('The target database DSN carries no host.');
    }

    public static function missingName(): self
    {
        return new self('The target database DSN carries no database name.');
    }

    public static function unixSocket(): self
    {
        return new self('The target database DSN uses a unix socket, which the client cannot target: use a host and a port.');
    }
}
