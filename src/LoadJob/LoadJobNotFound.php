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

final class LoadJobNotFound extends \RuntimeException
{
    public static function withId(LoadJobId $id): self
    {
        return new self(\sprintf('No load job known as "%s".', $id->value));
    }
}
