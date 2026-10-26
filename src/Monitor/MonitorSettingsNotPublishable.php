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

namespace DumpLoader\Monitor;

final class MonitorSettingsNotPublishable extends \RuntimeException
{
    public static function in(string $path): self
    {
        return new self(\sprintf('The monitor settings could not be published to "%s": make its directory writable by the PHP user.', $path));
    }
}
