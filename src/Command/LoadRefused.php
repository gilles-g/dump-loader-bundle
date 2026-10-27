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

namespace DumpLoader\Command;

final class LoadRefused extends \RuntimeException
{
    public static function cancelled(): self
    {
        return new self('The load was cancelled before it started.');
    }

    public static function withoutForce(string $database): self
    {
        return new self(\sprintf('Cannot drop every table of "%s" without confirmation: pass --force to load without being asked.', $database));
    }

    public static function withoutDump(): self
    {
        return new self('Cannot choose a dump without interaction: pass the path of the dump to load.');
    }

    /**
     * @param list<string> $unknown
     * @param list<string> $available
     */
    public static function unknownOptions(array $unknown, array $available): self
    {
        return new self(\sprintf(
            'There is no option named "%s"; available options: %s.',
            implode('", "', $unknown),
            [] === $available ? 'none' : implode(', ', $available),
        ));
    }
}
