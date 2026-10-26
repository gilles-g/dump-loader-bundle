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

namespace DumpLoader\LoadJob\File;

final class JobDirectoryNotWritable extends \RuntimeException
{
    public static function at(string $directory): self
    {
        return new self(\sprintf('The load job directory "%s" is not writable.', $directory));
    }
}
