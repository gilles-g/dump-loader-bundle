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

namespace DumpLoader\LoadJob;

final class SnapshotDate
{
    private const FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @return ($date is null ? null : string)
     */
    public static function format(?\DateTimeImmutable $date): ?string
    {
        return $date?->format(self::FORMAT);
    }

    /**
     * @return ($value is null ? null : \DateTimeImmutable)
     */
    public static function parse(?string $value): ?\DateTimeImmutable
    {
        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat(self::FORMAT, $value);

        if (false === $date) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a readable snapshot date.', $value));
        }

        return $date;
    }
}
